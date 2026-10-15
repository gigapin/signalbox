# Isolate Linux frontend dependencies from the host's native node_modules directory.
FROM node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392

# Match the bind-mounted source owner and initialize the dependency volume permissions.
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN mkdir -p /var/www/html/node_modules \
    && chown "$LOCAL_UID:$LOCAL_GID" /var/www/html/node_modules
WORKDIR /var/www/html
USER ${LOCAL_UID}:${LOCAL_GID}
CMD ["npm", "run", "build"]
