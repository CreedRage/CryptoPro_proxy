FROM debian:buster-slim

EXPOSE 323/tcp

ARG TZ=Europe/Moscow
ENV PATH="/opt/cprocsp/bin/amd64:/opt/cprocsp/sbin/amd64:${PATH}"

COPY dist/certificate.pfx /etc/stunnel/

ENV STUNNEL_HOST="91.215.37.229:3080"
ENV STUNNEL_HTTP_PROXY=
ENV STUNNEL_HTTP_PROXY_PORT=3080
ENV STUNNEL_HTTP_PROXY_CREDENTIALS=
ENV STUNNEL_DEBUG_LEVEL=5
ENV STUNNEL_CERTIFICATE_PFX_FILE=/etc/stunnel/certificate.pfx
ENV STUNNEL_CERTIFICATE_PIN_CODE=123

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
	&& echo $TZ > /etc/timezone \
	&& apt-get update \
	&& apt-get -y install lsb-base curl socat \
	&& rm -rf /var/lib/apt/lists/*
	
WORKDIR /dist
COPY dist/csp_deb.tgz csp_deb.tgz
RUN tar -zxvf csp_deb.tgz --strip-components=1 \
	&& ./install.sh cprocsp-stunnel
	
WORKDIR /

COPY conf/ /etc/stunnel
COPY bin/docker-entrypoint.sh docker-entrypoint.sh
COPY bin/stunnel-socat.sh stunnel-socat.sh

RUN chmod +x /docker-entrypoint.sh /stunnel-socat.sh
	
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["stunnel_thread", "/etc/stunnel/stunnel.conf"]