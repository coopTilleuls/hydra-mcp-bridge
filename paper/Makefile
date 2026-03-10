TEX = pdflatex
BIB = bibtex
MMDC = npx -y @mermaid-js/mermaid-cli@latest
FLAGS = -interaction=nonstopmode -halt-on-error
SRC = paper

DIAGRAMS = diagram-sequence.pdf

.PHONY: all clean diagrams

all: $(SRC).pdf

diagrams: $(DIAGRAMS)

diagram-sequence.pdf: diagram-sequence.mmd
	$(MMDC) -i $< -o diagram-sequence-raw.pdf -t neutral -b transparent --scale 2 -c mermaid-config.json -w 1200
	pdfcrop diagram-sequence-raw.pdf $@
	rm -f diagram-sequence-raw.pdf
	@echo "Built $@"

$(SRC).pdf: $(SRC).tex references.bib $(DIAGRAMS)
	$(TEX) $(FLAGS) $(SRC) > /dev/null
	-$(BIB) $(SRC)
	$(TEX) $(FLAGS) $(SRC) > /dev/null
	$(TEX) $(FLAGS) $(SRC) > /dev/null
	@echo "Built $(SRC).pdf"

clean:
	rm -f $(SRC).{aux,bbl,blg,log,out,pdf,toc} diagram-sequence.pdf diagram-components.pdf
