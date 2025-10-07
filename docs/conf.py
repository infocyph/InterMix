# docs/conf.py — InterMix (Sphinx 8.x / Python 3.13, Book Theme)
from __future__ import annotations
import os, datetime
from subprocess import Popen, PIPE

project   = "infocyph/InterMix"
author    = "A. B. M. Mahmudul Hasan"
year_now  = datetime.date.today().strftime("%Y")
copyright = f"2021-{year_now}, infocyph"

def get_version() -> str:
    if os.environ.get("READTHEDOCS") == "True":
        v = os.environ.get("READTHEDOCS_VERSION")
        if v:
            return v
    try:
        pipe = Popen("git rev-parse --abbrev-ref HEAD", stdout=PIPE, shell=True, universal_newlines=True)
        v = (pipe.stdout.read() or "").strip()
        return v or "latest"
    except Exception:
        return "latest"

version = get_version()
release = version
language = "en"
root_doc = "index"  # Sphinx 8

# --- PHP highlighting --------------------------------------------------------
from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers
highlight_language = "php"
lexers["php"]             = PhpLexer(startinline=True)
lexers["php-annotations"] = PhpLexer(startinline=True)

# --- Extensions --------------------------------------------------------------
extensions = [
    "myst_parser",
    "sphinx.ext.autodoc",
    "sphinx.ext.todo",
    "sphinx.ext.napoleon",
    "sphinx.ext.autosectionlabel",
    "sphinx.ext.intersphinx",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxcontrib.phpdomain",
    "sphinx.ext.extlinks",
]

# MyST (Markdown)
myst_enable_extensions = [
    "colon_fence",
    "deflist",
    "attrs_block",
    "attrs_inline",
    "tasklist",
    "fieldlist",
    "linkify",
]
myst_heading_anchors = 3

# Autodoc/Napoleon
autodoc_default_options = {
    "members": True,
    "undoc-members": True,
    "show-inheritance": True,
}
napoleon_google_docstring = True
napoleon_numpy_docstring  = False

# Intersphinx (only inventories that exist)
intersphinx_mapping = {
    "python": ("https://docs.python.org/3", None),
}

# PHP manual shortcut: :php:`json_encode`
extlinks = {
    "php": ("https://www.php.net/%s", "%s"),
}

# --- HTML output -------------------------------------------------------------
html_theme = "sphinx_book_theme"
html_theme_options = {
    "repository_url": "https://github.com/infocyph/InterMix",
    "repository_branch": "main",
    "path_to_docs": "docs",
    "use_repository_button": True,
    "use_issues_button": True,
    "use_download_button": True,   # PDF/ePub from RTD
    "home_page_in_toc": True,
    "show_toc_level": 2,           # depth in right sidebar
}
templates_path   = ["_templates"]
html_static_path = ["_static"]
html_css_files   = ["theme.css"]
html_title       = f"infocyph/InterMix {version} Manual"
html_show_sourcelink = True
html_show_sphinx    = False
html_last_updated_fmt = "%Y-%m-%d"

# --- PDF (LaTeX) options (optional) -----------------------------------------
latex_engine = "xelatex"
latex_elements = {
    "papersize": "a4paper",
    "pointsize": "11pt",
    "preamble": "",
    "figure_align": "H",
}

# --- GitHub context ----------------------------------------------------------
html_context = {
    "display_github": False,   # book theme uses the repo buttons above
    "github_user": "infocyph",
    "github_repo": "InterMix",
    "github_version": version,
    "conf_py_path": "/docs/",
}

# Replaceable year token for RST
rst_prolog = f"""
.. |current_year| replace:: {year_now}
"""
