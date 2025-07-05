FROM php:8.2-apache

# Enable Apache mod_rewrite (optional but often useful)
RUN a2enmod rewrite

# Copy app files to the container
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/

# Give write permissions if needed (for chatbot_rate_limit.txt)
RUN chmod -R 755 /var/www/html
