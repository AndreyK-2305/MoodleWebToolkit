PLUGINS APROBADOS PARA MOODLE 5.2 (V8)

V8 resuelve automáticamente los candidatos conocidos desde
config/plugin-compatibility-catalog.json y produce un plugin-lock por ejecución.
Este directorio solo contiene los checkouts exactos seleccionados; no copie
plugins sin pin ni sustituya un commit por latest.

Coloque el checkout Git de cada plugin en su ruta real dentro de Moodle 5.2,
por ejemplo public/mod/hvp o mod/hvp según la distribución. El repositorio
debe conservar .git; los submodules, incluido H5P cuando aplique, deben estar
inicializados recursivamente en commits concretos.

  git clone --recurse-submodules URL docker/custom-plugins/RUTA
  git -C docker/custom-plugins/RUTA checkout COMMIT_APROBADO
  git -C docker/custom-plugins/RUTA submodule update --init --recursive
  git -C docker/custom-plugins/RUTA status --porcelain
  git -C docker/custom-plugins/RUTA submodule status --recursive

El archivo approved-plugins.json declara un objeto por plugin adicional:
component, path, version, commit, tree_sha256, submodules y release si
version.php lo declara; si no, use release:null o omita la propiedad. Para cada
submodule: path relativo al checkout, commit y tree_sha256. Se exige que la
versión, release declarado y component coincidan literalmente con version.php, que el
checkout esté limpio, y que el árbol de archivos coincida con el hash aprobado.

El SHA-256 de árbol concatena, en orden lexicográfico, ruta relativa UTF-8,
NUL, SHA-256 hexadecimal del archivo, NUL; se omiten entradas .git. La función
pin_tree() de docker/verify-plugin-pins.php permite calcularlo durante una
revisión local. Registre previamente cada valor aprobado; nunca genere pins
automáticamente de un checkout no revisado.

Para customcertelement_daterange, aprobado upstream en el commit
dba041707207b825dcfd955d5fd4fe017e5d6c14, cree un segundo checkout Git
en public/mod/customcert/element/daterange. Declare pins separados para
mod_customcert (public/mod/customcert) y customcertelement_daterange (ruta
anidada, version 2026042000, release:null). El hash del padre excluye el
subárbol de cada hijo declarado; el hijo conserva su propio commit y hash.
No añada el hijo al índice Git del padre ni declare su ruta como submodule.
Obtenga y revise el tree_sha256 real antes de registrar el pin; este paquete
no incluye el repositorio externo ni un hash inventado.

Para mod_evoting 2024042302/v4.0, nunca use una versión vieja por tener el
mismo nombre. Si la institución aporta el código compatible, prepare un
checkout Git local verificado con remote.origin.url apuntando a la custodia
institucional, y declare provenance con kind:"institutional_git", source
(expediente de custodia), origin (URL/ruta exacta del remote), responsible y
reviewed_at (AAAA-MM-DD). El verificador exige commit/tree/version/release y
origin coincidentes, más aprobación manual. No descarga ningún plugin.

mod_chat retirado del core destino con actividades de origen queda en estado
removed_core_component y se bloquea: mod_openchat no transforma MBZ ni datos.
Se requiere diseñar/verificar una migración o preservación antes de aprobarlo.

La construcción de Docker se detiene ante directorios sin pin, commits sucios,
submodules ausentes o cambiados, diferencias en version.php o en los hashes.
La auditoría de fase 2 compara además el inventario instalado con los pins y
bloquea módulos utilizados aunque Moodle los haya deshabilitado.
