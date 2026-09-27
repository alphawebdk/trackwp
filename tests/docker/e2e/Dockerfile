FROM mcr.microsoft.com/playwright:v1.48.0-jammy
WORKDIR /workspace
COPY entrypoint.sh /usr/local/bin/trackwp-e2e-entrypoint.sh
RUN chmod +x /usr/local/bin/trackwp-e2e-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/trackwp-e2e-entrypoint.sh"]
CMD ["npx", "playwright", "test"]
