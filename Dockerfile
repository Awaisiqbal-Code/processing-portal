FROM php:8.2-cli-alpine

# Install SQLite, Python 3 (for PDF dossier compilation), and required libraries
RUN apk add --no-cache \
    sqlite \
    sqlite-libs \
    python3 \
    py3-pip \
    py3-pillow \
    py3-reportlab \
    && pip3 install --no-cache-dir --break-system-packages pypdf \
    && rm -rf /var/cache/apk/*

WORKDIR /app

# Copy application files and existing SQLite database
COPY portal /app/portal
COPY start-railway.sh /app/start-railway.sh

RUN sed -i 's/\r$//' /app/start-railway.sh && chmod +x /app/start-railway.sh \
    && mkdir -p /app/portal/uploads/cache /app/portal/uploads/documents \
    && chmod -R 775 /app/portal/app /app/portal/uploads

EXPOSE 8000

CMD ["/app/start-railway.sh"]
