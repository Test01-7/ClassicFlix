"""AWS Lambda Event Function: private ClassicFlix posters -> private JPEG thumbnails.

Deploy in the S3 bucket's region with the Pillow layer. No MySQL credentials,
HTTP endpoint, public ACL or connection to EC2 is needed.
"""

import io
import os
import re
import warnings
from urllib.parse import unquote_plus

from PIL import Image, ImageOps, UnidentifiedImageError

MAX_BYTES = 5 * 1024 * 1024
Image.MAX_IMAGE_PIXELS = 20_000_000
warnings.simplefilter("error", Image.DecompressionBombWarning)
SOURCE_PATTERN = re.compile(
    r"classicflix/([1-9][0-9]*)/portada/([a-f0-9]{32})\.(?:jpg|png|webp)"
)


def thumbnail_key(source_key):
    """Keep in sync with S3Storage::thumbnailKey; never accept arbitrary S3 keys."""
    match = SOURCE_PATTERN.fullmatch(source_key)
    return f"miniaturas/v1/{match[1]}/{match[2]}.jpg" if match else None


def resize_image(data):
    if len(data) > MAX_BYTES:
        raise ValueError("Portada demasiado grande")
    with Image.open(io.BytesIO(data)) as original:
        if original.format not in {"JPEG", "PNG", "WEBP"}:
            raise ValueError("Formato no admitido")
        image = ImageOps.exif_transpose(original)
        image.thumbnail((400, 560), Image.Resampling.LANCZOS)
        rgba = image.convert("RGBA")
        result = Image.new("RGB", rgba.size, "#f4eee0")
        result.paste(rgba, mask=rgba.getchannel("A"))
        output = io.BytesIO()
        # EXIF and other original metadata are not copied into the thumbnail.
        result.save(output, format="JPEG", quality=80, optimize=True)
        return output.getvalue()


def _s3_client():
    # The Lambda runtime supplies boto3; the layer only contains Pillow.
    import boto3

    return boto3.client("s3")


def lambda_handler(event, context):
    bucket_name = os.environ.get("SOURCE_BUCKET", "mi-primer-bucket-04-07-2006").strip()
    if not bucket_name:
        raise ValueError("Configura SOURCE_BUCKET")
    client = None
    processed = 0
    for record in event.get("Records", []):
        if record.get("eventSource") != "aws:s3":
            continue
        if not record.get("eventName", "").startswith("ObjectCreated:"):
            continue
        bucket = record["s3"]["bucket"]["name"]
        key = unquote_plus(record["s3"]["object"]["key"])
        destination = thumbnail_key(key)
        if bucket != bucket_name or destination is None:
            continue
        if client is None:
            client = _s3_client()
        arguments = {"Bucket": bucket, "Key": key}
        etag = record["s3"]["object"].get("eTag")
        if etag:
            arguments["IfMatch"] = '"' + etag.strip('"') + '"'
        try:
            response = client.get_object(**arguments)
        except Exception as error:
            code = getattr(error, "response", {}).get("Error", {}).get("Code")
            if code in {"NoSuchKey", "PreconditionFailed", "412"}:
                continue
            # Transient AWS/permission failures must remain visible and allow retries.
            raise
        body = response["Body"]
        try:
            data = body.read(MAX_BYTES + 1)
        finally:
            body.close()
        try:
            thumbnail = resize_image(data)
        except (
            ValueError, UnidentifiedImageError, OSError,
            Image.DecompressionBombError, Image.DecompressionBombWarning,
        ) as error:
            print({"rejected": key, "reason": type(error).__name__})
            continue
        # Repeated notifications write the same immutable-source derivative.
        # Destination is outside classicflix/, preventing recursive triggers.
        client.put_object(
            Bucket=bucket, Key=destination, Body=thumbnail,
            ContentType="image/jpeg",
        )
        processed += 1
        print({
            "thumbnail": destination,
            "original_bytes": len(data),
            "thumbnail_bytes": len(thumbnail),
        })
    return {"processed": processed}
