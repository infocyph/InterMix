# docs/conf.py — InterMix (updated for Sphinx 8.x / Python 3.13)
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

from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers
highlight_language = "php"
lexers["php"]             = PhpLexer(startinline=True)
lexers["php-annotations"] = PhpLexer(startinline=True)

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

autodoc_default_options = {
    "members": True,
    "undoc-members": True,
    "show-inheritance": True,
}
napoleon_google_docstring = True
napoleon_numpy_docstring  = False

intersphinx_mapping = {
    "python": ("https://docs.python.org/3", None),
}

extlinks = {
    "php": ("https://www.php.net/%s", "%s"),
}

html_theme = "sphinx_rtd_theme"
html_theme_options = {
    "collapse_navigation": False,
    "sticky_navigation": True,
    "navigation_depth": 3,
    "logo_only": False,
    "style_external_links": True,
    "display_version": False,
}
templates_path   = ["_templates"]
html_static_path = ["_static"]
html_css_files   = ["theme.css"]
html_title       = f"infocyph/InterMix {version} Manual"
html_show_sourcelink = True
html_show_sphinx    = False
html_last_updated_fmt = "%Y-%m-%d"

latex_engine = "xelatex"
latex_elements = {
    "papersize": "a4paper",
    "pointsize": "11pt",
    "preamble": "",
    "figure_align": "H",
}

html_context = {
    "display_github": False,
    "github_user": "infocyph",
    "github_repo": "InterMix",
    "github_version": version,
    "conf_py_path": "/docs/",
}

rst_prolog = f"""
.. |current_year| replace:: {year_now}
"""
