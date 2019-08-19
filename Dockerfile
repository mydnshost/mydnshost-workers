FROM mydnshost/mydnshost-api AS api

FROM mydnshost/mydnshost-api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

COPY --from=api /dnsapi /dnsapi

COPY . /dnsapi/workers

ENTRYPOINT ["/dnsapi/workers/TaskRunner.php"]
