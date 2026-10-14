import sys, os
from pathlib import Path

BASE = Path(__file__).resolve().parent
os.environ.setdefault("PYTHONPATH", str(BASE))

# додаємо віртуальне оточення до PATH (якщо треба)
venv_bin = BASE / ".venv" / "bin"
if venv_bin.exists():
    os.environ["PATH"] = f"{venv_bin}:{os.environ.get('PATH','')}"
    os.environ["PYTHONHOME"] = str(BASE / ".venv")

sys.path.insert(0, str(BASE))
from app import app as application
