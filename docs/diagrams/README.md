# Diagrams

Paired light/dark Graphviz diagrams for the ARCP PHP SDK. Edit the
`.dot` sources; render with `dot -Tsvg`.

## Architecture

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="architecture-dark.svg">
  <img alt="ARCP PHP SDK architecture" src="architecture-light.svg">
</picture>

## Render

```sh
cd docs/diagrams
for f in *.dot; do dot -Tsvg "$f" -o "${f%.dot}.svg"; done
```

`graphviz` provides `dot`. On macOS: `brew install graphviz`. On
Debian/Ubuntu: `apt-get install -y graphviz`.
