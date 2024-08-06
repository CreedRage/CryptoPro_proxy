FROM debian:buster-slim

EXPOSE 323/tcp
EXPOSE 333/tcp

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
	&& apt-get -y install lsb-base curl socat php php-cli php-xml php-mbstring \
	&& rm -rf /var/lib/apt/lists/*

COPY conf/php.ini /etc/php/7.3/cli/php.ini
COPY conf/php.ini /etc/php/7.3/apache2/php.ini

WORKDIR /dist
COPY dist/csp_deb.tgz csp_deb.tgz
RUN tar -zxvf csp_deb.tgz --strip-components=1 \
	&& ./install.sh cprocsp-stunnel

WORKDIR /

COPY conf/ /etc/stunnel
COPY bin/docker-entrypoint.sh docker-entrypoint.sh
COPY bin/stunnel-socat.sh stunnel-socat.sh
COPY bin/sign.php /var/www/html/sign.php

RUN chmod +x /docker-entrypoint.sh /stunnel-socat.sh

CMD ["/bin/bash", "-c", "/docker-entrypoint.sh & php -S 0.0.0.0:333 -t /var/www/html"]

ENTRYPOINT ["/docker-entrypoint.sh"]