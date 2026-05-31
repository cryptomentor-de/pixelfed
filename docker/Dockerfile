# Pixelfed patch image
# Build context: repo root (kaniko clones the full fork, context=dir:///workspace)
# Dockerfile: docker/Dockerfile
#
# Local build (from repo root):
#   docker build --build-arg BASE_TAG=v0.12.7 -f docker/Dockerfile .
ARG BASE_TAG=v0.12.7
FROM rg.fr-par.scw.cloud/cryptomentor/pixelfed:${BASE_TAG}

# #6609, #6610, RESTRICTED_INSTANCE: Handler, ApiV1Controller, Kernel, RestrictedAccess
COPY app/Exceptions/Handler.php \
     /var/www/html/app/Exceptions/Handler.php
COPY app/Http/Controllers/Api/ApiV1Controller.php \
     /var/www/html/app/Http/Controllers/Api/ApiV1Controller.php
COPY app/Http/Kernel.php \
     /var/www/html/app/Http/Kernel.php
COPY app/Http/Middleware/RestrictedAccess.php \
     /var/www/html/app/Http/Middleware/RestrictedAccess.php

# #6612: orient() requires the PHP exif extension (not in upstream image)
USER root
RUN docker-php-ext-install exif
USER www-data
COPY app/Util/Media/Image.php \
     /var/www/html/app/Util/Media/Image.php

# #6611: static assets absent from release image (storage/ in .gitignore)
COPY docker/default.jpg    /var/www/html/storage/app/public/avatars/default.jpg
COPY docker/default.png    /var/www/html/storage/app/public/avatars/default.png
COPY docker/no-preview.png /var/www/html/storage/app/public/no-preview.png

# #6617: video pipeline — S3 upload, faststart re-mux, video/quicktime support
COPY app/Jobs/VideoPipeline/VideoThumbnail.php \
     /var/www/html/app/Jobs/VideoPipeline/VideoThumbnail.php
COPY app/Jobs/VideoPipeline/VideoThumbnailToCloudPipeline.php \
     /var/www/html/app/Jobs/VideoPipeline/VideoThumbnailToCloudPipeline.php
COPY app/Jobs/VideoPipeline/VideoHlsPipeline.php \
     /var/www/html/app/Jobs/VideoPipeline/VideoHlsPipeline.php
