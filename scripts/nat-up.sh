#!/usr/bin/env bash
set -euo pipefail

AWS_REGION=eu-central-1
PUBLIC_SUBNET_ID=subnet-0f8c8651e4632ca2a
PRIVATE_ROUTE_TABLE_ID=rtb-0e1691b40fe8da10b
state=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/.nat-lab3.state
aws_cli() { aws --region "$AWS_REGION" --no-cli-pager "$@"; }

account=$(aws_cli sts get-caller-identity --query Account --output text)
current=$(aws_cli ec2 describe-route-tables --route-table-ids "$PRIVATE_ROUTE_TABLE_ID" \
  --query 'length(RouteTables[0].Routes[?DestinationCidrBlock==`0.0.0.0/0`])' --output text)
[[ $current == 0 ]] || { echo 'Private default route already exists; not modifying it.' >&2; exit 1; }
umask 077
mkdir -- "$state" || { echo 'State already exists; inspect it or run nat-down.sh.' >&2; exit 1; }
printf '%s\n' "$account" > "$state/account"
trap 'echo "Setup interrupted; inspect AWS and run scripts/nat-down.sh with the same account." >&2' ERR

tags='{Key=Project,Value=timecapsule},{Key=Environment,Value=dev},{Key=Owner,Value=gedu},{Key=Lab,Value=lab3}'
allocation=$(aws_cli ec2 allocate-address --domain vpc \
  --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=timecapsule-dev-nat-a-eip},$tags]" \
  --query AllocationId --output text)
[[ $allocation =~ ^eipalloc-[0-9a-f]+$ ]]
printf '%s\n' "$allocation" > "$state/allocation"

nat=$(aws_cli ec2 create-nat-gateway --subnet-id "$PUBLIC_SUBNET_ID" \
  --allocation-id "$allocation" --connectivity-type public --availability-mode zonal \
  --tag-specifications "ResourceType=natgateway,Tags=[{Key=Name,Value=timecapsule-dev-nat-a},$tags]" \
  --query NatGateway.NatGatewayId --output text)
[[ $nat =~ ^nat-[0-9a-f]+$ ]]
printf '%s\n' "$nat" > "$state/gateway"
printf 'Waiting for %s (%s)…\n' "$nat" "$allocation"
aws_cli ec2 wait nat-gateway-available --nat-gateway-ids "$nat"
aws_cli ec2 create-route --route-table-id "$PRIVATE_ROUTE_TABLE_ID" \
  --destination-cidr-block 0.0.0.0/0 --nat-gateway-id "$nat"
printf 'NAT active; run scripts/nat-down.sh immediately after maintenance.\n'
