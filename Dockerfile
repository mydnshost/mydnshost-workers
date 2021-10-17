FROM registry.shanemcc.net/mydnshost-public/mydnshost-api-base AS api

FROM registry.shanemcc.net/mydnshost-public/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

EXPOSE ""

COPY --from=api /dnsapi /dnsapi

COPY . /dnsapi/workers

ENTRYPOINT ["/dnsapi/workers/TaskRunner.php"]
