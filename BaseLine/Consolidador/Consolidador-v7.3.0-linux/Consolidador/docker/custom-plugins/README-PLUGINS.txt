COLOQUE AQUÍ SOLO PLUGINS COMPATIBLES CON MOODLE 5.2

Conserve la ruta que el plugin tendría dentro del código Moodle:

  docker/custom-plugins/mod/nombre_plugin/
  docker/custom-plugins/auth/nombre_plugin/
  docker/custom-plugins/local/nombre_plugin/
  docker/custom-plugins/theme/nombre_plugin/

No copie config.php, moodledata, archivos de usuario ni plugins de Moodle 4.5
sin comprobar primero que exista una versión compatible con Moodle 5.2.

Después de agregar o actualizar plugins ejecute:

  ./moodle-consolidation.sh preparar

La etapa 2 volverá a construir el destino y auditará la compatibilidad.
