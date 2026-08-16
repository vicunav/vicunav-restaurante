# Cómo contribuir

Este repositorio usa un flujo atómico: cada cambio corresponde a un issue, una
rama, un pull request (PR) y un único commit final en `main`.

La fuente normativa es [`docs/standards/docs/git.md`][flujo-git], incluida en el
submódulo `docs/standards/`. Después de clonar el repositorio, inicializa el
submódulo y lee esa norma:

```bash
git submodule update --init --recursive
```

## 1. Crea o selecciona un issue

Antes de modificar archivos, crea o selecciona un issue atómico. El issue debe
describir un solo objetivo e incluir criterios de aceptación verificables.

No mezcles trabajo de varios issues. Si aparece una necesidad fuera de alcance,
crea otro issue y atiéndela en otra rama.

## 2. Crea una rama desde `main`

Actualiza `main` y crea una rama exclusiva para el issue:

```bash
git switch main
git pull --ff-only origin main
git switch -c tipo/N-slug
```

Reemplaza:

- `tipo` por un tipo aprobado, como `feat`, `fix`, `docs`, `chore` o `refactor`.
- `N` por el número del issue, sin `#`.
- `slug` por un resumen en minúsculas ASCII y `kebab-case`.

Por ejemplo, para documentar el flujo del issue `#12`:

```text
docs/12-documentar-flujo-contribucion
```

## 3. Implementa y valida el cambio

Modifica únicamente lo necesario para cumplir el issue. Ejecuta el lint y las
pruebas definidas por el repositorio antes de publicar la rama.

Los commits usan Conventional Commits:

```bash
git add RUTA_DEL_ARCHIVO
git commit -m "tipo: descripción breve"
```

## 4. Abre un pull request hacia `main`

Publica la rama:

```bash
git push -u origin tipo/N-slug
```

Abre un PR con `main` como rama base. El título debe ser el mensaje Conventional
Commit que quedará en el historial. El cuerpo debe enlazar exactamente el issue
correspondiente mediante `Closes #N`.

Nunca hagas push directo a `main`.

### Checklist del pull request

- [ ] El diff contiene únicamente el alcance del issue.
- [ ] El lint finaliza en verde.
- [ ] Las pruebas relevantes pasan.
- [ ] El cuerpo del PR incluye `Closes #N` con el número correcto.
- [ ] Todos los criterios de aceptación del issue están cumplidos.
- [ ] El título del PR cumple Conventional Commits.

## 5. Revisa y fusiona

Después de la revisión y aprobación, fusiona exclusivamente mediante
**Squash and merge**. No uses **Create a merge commit** ni
**Rebase and merge**.

El resultado en `main` debe ser un único commit para el issue. Elimina la rama
después del merge.

[flujo-git]: docs/standards/docs/git.md
