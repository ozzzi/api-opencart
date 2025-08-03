FROM opensearchproject/opensearch:3.0.0

RUN /usr/share/opensearch/bin/opensearch-plugin install analysis-phonetic --batch

ENTRYPOINT ["/usr/share/opensearch/opensearch-docker-entrypoint.sh"]
CMD ["opensearch"]
