"""Offline packaging contract: complete local runtime, no suite dependencies."""
import json, re, subprocess, shutil, unittest, tempfile, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ADDON=ROOT/'addons/radius'
class SourceContract(unittest.TestCase):
    def test_complete_package(self):
        features=json.loads((ADDON/'package_features.json').read_text())
        self.assertEqual(features['version'],(ROOT/'VERSION').read_text().strip())
        for feature in ('complete_standalone','provider_shortcut','current_state','preferences','connection_tools','accounting_read_only'):
            self.assertIs(features[feature],True)
        for path in ('access.php','standalone_access.php','current_state.php','preferences.php','connection_settings_api.php','connection_settings_model.php','radius_log_reader.php','assets/connection_settings.js','assets/connection_settings.css','assets/radius_preferences.js'):
            self.assertTrue((ADDON/path).is_file(),path)
    def test_runtime_has_no_suite_includes(self):
        for path in ADDON.rglob('*'):
            if path.suffix not in ('.php','.hhvm','.js'):continue
            text=path.read_text(encoding='utf8')
            self.assertNotRegex(text,r"(?:require|include)[^;\n]*(?:shared/|dashboard/|busca_inteligente/)",str(path))
            self.assertNotIn('shell_exec(',text,str(path))
        self.assertIn('405',(ADDON/'run_script.hhvm').read_text())
        self.assertNotIn('DELETE FROM radacct',(ADDON/'run_script.hhvm').read_text())
    @unittest.skipUnless(shutil.which('php'),'PHP CLI not present')
    def test_php_syntax(self):
        for path in ADDON.rglob('*'):
            if path.suffix in ('.php','.hhvm'):subprocess.run(['php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
    @unittest.skipUnless(shutil.which('node'),'Node not present')
    def test_js_syntax(self):
        for path in ADDON.rglob('*.js'):subprocess.run(['node','--check',str(path)],check=True,capture_output=True)
        sys.path.insert(0,str(ROOT/'installers'))
        from install_menu import BLOCK
        with tempfile.TemporaryDirectory()as folder:
            script=Path(folder)/'menu.js';script.write_text(BLOCK)
            subprocess.run(['node',str(ROOT/'tests/test_menu.js'),str(script)],check=True)
if __name__=='__main__':unittest.main()
