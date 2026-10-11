"""Pure synthetic tests; never access the real VPS env or a database."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('staging_auth', Path(__file__).with_name('prepare-staging-redis-auth.py'))
auth = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auth)


class StagingAuthTests(unittest.TestCase):
    def test_preserves_existing_nonempty_values_byte_for_byte(self):
        original = b'APP_ENV=staging\r\nAPI_APP_KEY=synthetic\r\nDATABASE_URL=synthetic\r\nREDIS_PASSWORD=existing\r\nREDIS_CACHE_PASSWORD=existing-cache'
        with patch.object(auth.secrets, 'token_hex') as generate:
            self.assertEqual(auth.prepare_content(original), (original, []))
            generate.assert_not_called()

    def test_fills_missing_keys_preserving_other_lines(self):
        original = b'# synthetic only\nAPP_ENV=staging\nAPI_APP_KEY=synthetic\nDATABASE_URL=synthetic'
        updated, changed = auth.prepare_content(original)
        self.assertTrue(updated.startswith(original + b'\n'))
        self.assertEqual(changed, ['REDIS_PASSWORD', 'REDIS_CACHE_PASSWORD'])
        self.assertRegex(updated, rb'REDIS_PASSWORD=[0-9a-f]{64}\n')
        self.assertRegex(updated, rb'REDIS_CACHE_PASSWORD=[0-9a-f]{64}\n')
        self.assertEqual(auth.prepare_content(updated), (updated, []))

    def test_only_replaces_empty_key_and_keeps_crlf(self):
        original = b'APP_ENV=staging\r\nREDIS_PASSWORD=""\r\nREDIS_CACHE_PASSWORD=existing\r\n'
        updated, changed = auth.prepare_content(original)
        self.assertEqual(changed, ['REDIS_PASSWORD'])
        self.assertTrue(updated.endswith(b'REDIS_CACHE_PASSWORD=existing\r\n'))
        self.assertNotIn(b'\n', updated.replace(b'\r\n', b''))

    def test_refuses_production_missing_identity_and_duplicates(self):
        for original in (b'APP_ENV=production\n', b'APP_ENV=Staging\n', b'REDIS_PASSWORD=\n',
                         b'APP_ENV=staging\nAPP_ENV=staging\n', b'APP_ENV=staging\nREDIS_PASSWORD=a\nREDIS_PASSWORD=b\n'):
            with self.subTest(original=original), patch.object(auth.secrets, 'token_hex') as generate:
                with self.assertRaises(ValueError):
                    auth.prepare_content(original)
                generate.assert_not_called()

    def test_refuses_semantic_duplicates_hidden_by_export_or_whitespace(self):
        for hidden in (b'export REDIS_PASSWORD=existing\n', b' REDIS_PASSWORD=existing\n', b'REDIS_CACHE_PASSWORD =existing\n'):
            with self.subTest(hidden=hidden), patch.object(auth.secrets, 'token_hex') as generate:
                with self.assertRaises(ValueError):
                    auth.prepare_content(b'APP_ENV=staging\n' + hidden)
                generate.assert_not_called()


if __name__ == '__main__':
    unittest.main()
