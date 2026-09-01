FROM docker:27.5.1-cli AS docker_cli

FROM mcr.microsoft.com/powershell:7.4-ubuntu-22.04

COPY --from=docker_cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker_cli \
    /usr/local/libexec/docker/cli-plugins \
    /usr/local/libexec/docker/cli-plugins

ENV POWERSHELL_TELEMETRY_OPTOUT=1
CMD ["pwsh", "-NoLogo", "-NoProfile"]
