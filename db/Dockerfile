FROM mariadb:11

COPY db/db-entrypoint.sh /usr/local/bin/db-entrypoint.sh
COPY db/migrate-on-start.sh /usr/local/bin/migrate-on-start.sh
COPY scripts/baseline-imported-db.sh /opt/certif/scripts/baseline-imported-db.sh
COPY scripts/verify-release.sh /opt/certif/scripts/verify-release.sh

RUN chmod +x /usr/local/bin/db-entrypoint.sh \
  /usr/local/bin/migrate-on-start.sh \
  /opt/certif/scripts/baseline-imported-db.sh \
  /opt/certif/scripts/verify-release.sh

ENTRYPOINT ["/bin/bash", "/usr/local/bin/db-entrypoint.sh"]
CMD ["mariadbd"]
