"""Real Pillow, fake S3: no boto3 installation, AWS account or network required."""

import importlib.util
import io
import os
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("thumbnail_lambda", ROOT / "lambda/poster-thumbnail/lambda_function.py")
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)
KEY = "classicflix/12/portada/0123456789abcdef0123456789abcdef.png"
DESTINATION = "miniaturas/v1/12/0123456789abcdef0123456789abcdef.jpg"


def image_bytes(size=(1200, 1800), mode="RGB", format="PNG", **save_options):
    data = io.BytesIO()
    module.Image.new(mode, size).save(data, format=format, **save_options)
    return data.getvalue()


def event(key=KEY, bucket="test-bucket", name="ObjectCreated:Put"):
    return {"Records": [{"eventSource": "aws:s3", "eventName": name,
                         "s3": {"bucket": {"name": bucket}, "object": {"key": key, "eTag": "abc"}}}]}


class FakeAwsError(Exception):
    def __init__(self, code):
        self.response = {"Error": {"Code": code}}


class FakeS3:
    def __init__(self, data=None):
        self.data = image_bytes() if data is None else data
        self.gets = []
        self.puts = []
        self.objects = {}
        self.failure = None
        self.body = None

    def get_object(self, **arguments):
        self.gets.append(arguments)
        if self.failure:
            raise self.failure
        self.body = io.BytesIO(self.data)
        return {"Body": self.body}

    def put_object(self, **arguments):
        self.puts.append(arguments)
        self.objects[arguments["Key"]] = arguments["Body"]


class ThumbnailsTest(unittest.TestCase):
    def run_event(self, payload, client):
        with patch.dict(os.environ, {"SOURCE_BUCKET": "test-bucket"}), patch.object(module, "_s3_client", return_value=client):
            return module.lambda_handler(payload, None)

    def test_proportions_no_upscale_and_alpha(self):
        for size, mode in [((1200, 1800), "RGB"), ((200, 100), "RGB"), ((800, 1000), "RGBA")]:
            with self.subTest(size=size, mode=mode):
                result = module.resize_image(image_bytes(size, mode))
                with module.Image.open(io.BytesIO(result)) as image:
                    self.assertEqual(image.format, "JPEG")
                    self.assertEqual(image.mode, "RGB")
                    self.assertLessEqual(image.width, min(400, size[0]))
                    self.assertLessEqual(image.height, min(560, size[1]))
                    self.assertAlmostEqual(image.width / image.height, size[0] / size[1], delta=0.005)
                    if mode == "RGBA":
                        self.assertTrue(all(abs(a - b) <= 3 for a, b in zip(image.getpixel((0, 0)), (244, 238, 224))))

    def test_jpeg_png_and_webp_inputs(self):
        for format in ["JPEG", "PNG", "WEBP"]:
            with self.subTest(format=format):
                output = module.resize_image(image_bytes(format=format))
                self.assertTrue(output.startswith(b"\xff\xd8\xff"))

    def test_rotation_and_metadata_removed(self):
        exif = module.Image.Exif()
        exif[274] = 6
        exif[315] = "Private test author"
        output = module.resize_image(image_bytes((800, 400), format="JPEG", exif=exif))
        with module.Image.open(io.BytesIO(output)) as image:
            self.assertEqual(image.size, (280, 560))
            self.assertFalse(image.getexif())

    def test_output_path_and_no_arbitrary_keys(self):
        self.assertEqual(module.thumbnail_key(KEY), DESTINATION)
        for key in [DESTINATION, "classicflix/12/trailer/x.mp4", "classicflix/0/portada/" + "a" * 32 + ".jpg", KEY + ".html", "../" + KEY]:
            self.assertIsNone(module.thumbnail_key(key))

    def test_repeated_notifications_do_not_add_objects_or_acl(self):
        client = FakeS3()
        for _ in range(2):
            self.assertEqual(self.run_event(event(), client), {"processed": 1})
        self.assertEqual(list(client.objects), [DESTINATION])
        self.assertEqual(client.puts[0]["ContentType"], "image/jpeg")
        self.assertNotIn("ACL", client.puts[0])
        self.assertEqual(client.gets[0]["IfMatch"], '"abc"')
        self.assertTrue(client.body.closed)

    def test_ignored_events_do_not_access_s3(self):
        client = FakeS3()
        for payload in [event(DESTINATION), event("classicflix/12/pelicula/x.mp4"), event(bucket="other-bucket"), event(name="ObjectRemoved:Delete"), {"Event": "s3:TestEvent"}]:
            self.assertEqual(self.run_event(payload, client), {"processed": 0})
        self.assertFalse(client.gets)
        self.assertFalse(client.puts)

    def test_url_encoded_key(self):
        client = FakeS3()
        self.assertEqual(self.run_event(event(KEY.replace("/", "%2F")), client), {"processed": 1})
        self.assertEqual(client.gets[0]["Key"], KEY)

    def test_invalid_oversized_and_unsupported_files_rejected(self):
        for data in [b"not an image", b"x" * (module.MAX_BYTES + 1), image_bytes(format="GIF")]:
            client = FakeS3(data)
            self.assertEqual(self.run_event(event(), client), {"processed": 0})
            self.assertFalse(client.puts)
            self.assertTrue(client.body.closed)

    def test_decompression_limit(self):
        with patch.object(module.Image, "MAX_IMAGE_PIXELS", 100):
            with self.assertRaises((module.Image.DecompressionBombError, module.Image.DecompressionBombWarning)):
                module.resize_image(image_bytes((100, 100)))

    def test_missing_or_changed_original_skipped(self):
        for code in ["NoSuchKey", "PreconditionFailed", "412"]:
            client = FakeS3()
            client.failure = FakeAwsError(code)
            self.assertEqual(self.run_event(event(), client), {"processed": 0})
            self.assertFalse(client.puts)

    def test_aws_failures_remain_retryable(self):
        client = FakeS3()
        client.failure = FakeAwsError("AccessDenied")
        with self.assertRaises(FakeAwsError):
            self.run_event(event(), client)


if __name__ == "__main__":
    unittest.main(verbosity=2)
