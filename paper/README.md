# Paper: APIs We Built Are Meant for Computers

LaTeX source for the paper on bridging Hydra/HATEOAS APIs with the Model Context Protocol (MCP).

## Prerequisites

- `pdflatex` (TeX Live or similar)
- `bibtex`

## Build

```bash
cd paper
make
```

This runs `pdflatex` + `bibtex` + two more `pdflatex` passes to resolve all citations and references. Output: `paper.pdf`.

## Clean

```bash
make clean
```

## Files

- `paper.tex` — main LaTeX source
- `references.bib` — BibTeX bibliography
- `Makefile` — build automation
