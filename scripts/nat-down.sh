#!/usr/bin/env bash
set -euo pipefail

AWS_REGION=eu-central-1
PUBLIC_SUBNET_ID=subnet-0f8c8651e4632ca2a
PRIVATE_ROUTE_TABLE_ID=rtb-0e1691b40fe8da10b
state=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/.nat-lab3.state
aws_cli() { aws --region "$AWS_REGION" --no-cli-pager "$@"; }

[[ -d $state ]] || { echo 'No NAT state; no changes made.'; exit 0; }
account=$(aws_cli sts get-caller-identity --query Account --output text)
[[ $account == "$(<"$state/account")" ]] || { echo 'Wrong AWS account.' >&2; exit 1; }

if [[ -f $state/gateway ]]; then
  nat=$(<"$state/gateway")
  [[ $nat =~ ^nat-[0-9a-f]+$ ]]
  route=$(aws_cli ec2 describe-route-tables --route-table-ids "$PRIVATE_ROUTE_TABLE_ID" \
    --query 'RouteTables[0].Routes[?DestinationCidrBlock==`0.0.0.0/0`].NatGatewayId | [0]' --output text)
  if [[ $route == "$nat" ]]; then
    aws_cli ec2 delete-route --route-table-id "$PRIVATE_ROUTE_TABLE_ID" --destination-cidr-block 0.0.0.0/0
  fi

  status=$(aws_cli ec2 describe-nat-gateways --filter "Name=nat-gateway-id,Values=$nat" \
    "Name=subnet-id,Values=$PUBLIC_SUBNET_ID" --query 'NatGateways[0].State' --output text)
  case $status in
    None|deleted) ;;
    deleting) aws_cli ec2 wait nat-gateway-deleted --nat-gateway-ids "$nat" ;;
    pending|available|failed)
      aws_cli ec2 delete-nat-gateway --nat-gateway-id "$nat"
      aws_cli ec2 wait nat-gateway-deleted --nat-gateway-ids "$nat"
      ;;
    *) echo "Unexpected NAT state: $status" >&2; exit 1 ;;
  esac
  rm -- "$state/gateway"
fi

if [[ -f $state/allocation ]]; then
  allocation=$(<"$state/allocation")
  [[ $allocation =~ ^eipalloc-[0-9a-f]+$ ]]
  for attempt in 1 2 3 4 5; do
    exists=$(aws_cli ec2 describe-addresses --filters "Name=allocation-id,Values=$allocation" \
      --query 'length(Addresses)' --output text)
    if [[ $exists == 0 ]] || aws_cli ec2 release-address --allocation-id "$allocation"; then
      rm -- "$state/allocation"
      break
    fi
    if [[ $attempt == 5 ]]; then
      echo 'Could not release Elastic IP; state retained. Inspect AWS and retry.' >&2
      exit 1
    fi
    sleep 6
  done
fi

rm -- "$state/account"
rmdir -- "$state"
echo 'Temporary NAT, route and Elastic IP cleaned up.'
