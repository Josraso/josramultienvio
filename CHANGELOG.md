# Changelog - josramultienvio

## 1.0.2 - 2026-11-11
- Actualizaciones más a mano: en PrestaShop 8.1 o superior, al abrir Módulos > Gestor de módulos se pregunta al servidor en ese momento (como mucho cada 5 minutos), así que en la pestaña «Actualizaciones» se ve lo pendiente de todos los módulos sin entrar en cada uno. El aviso automático pasa de una vez al día a cada 6 horas. Sin cambios en el funcionamiento del módulo.

## 1.0.1 - 2026-11-11
- Clave propia del módulo en el servidor de actualizaciones: ya no comparte clave con otros módulos. Además avisa al servidor cuando se desinstala y del resultado de cada actualización hecha desde la configuración (si falla, con el motivo). Sin cambios en el funcionamiento del módulo.

## 1.0.0 - 2026-11-09
- Corrige el transporte de los pedidos que PrestaShop parte por transportista (carrito con productos que van con
  transportistas distintos, por ejemplo frío y normal). PrestaShop guarda en cada pedido el transporte de todo el
  carrito (10 + 50 = 60 en los dos) aunque el total esté bien; ahora cada pedido guarda solo el suyo (con y sin IVA),
  también en el transportista del pedido, la factura y los emails. Se calcula justo antes de guardar el pedido y solo
  se aplica si cuadra con el total del pedido; si no, se deja como está y se anota en el registro de PrestaShop.
- Checkout nativo (tema clásico, Warehouse, Hummingbird): aviso de que la compra irá en varios envíos con cada envío
  (productos, transportista, plazo y precio); en las opciones combinadas, nombre de todos los transportistas, plazos y
  desglose del precio; id único en cada opción (PrestaShop repetía el del último transportista).
- Configuración: interruptor para cada parte y texto del aviso por idioma.
- Conectado al servidor de actualizaciones de josra.
