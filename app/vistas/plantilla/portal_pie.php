    </main>
    </div><!-- /contenido__pagina -->
    </div><!-- /contenido__cuerpo -->
  </div><!-- /contenido -->
</div>

<!-- Solo lo necesario para leer: gráficas, historial desplegable e impresión (sin el resto de la app) -->
<script nonce="<?= nonceCsp() ?>" src="<?= recurso("js/graficas.js") ?>"></script>
<script nonce="<?= nonceCsp() ?>" src="<?= recurso("js/portal.js") ?>"></script>
<script nonce="<?= nonceCsp() ?>" src="<?= recurso("js/modulos/push.js") ?>"></script>
</body>
</html>
