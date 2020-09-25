FROM wordpress:latest

RUN apt update && apt install cron -y

COPY config/cron.conf /var/cronfile
RUN crontab -l 2>/dev/null; crontab -l -u root | cat - /var/cronfile | crontab -u root -
RUN crontab -l

RUN service cron start

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
