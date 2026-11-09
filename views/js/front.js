/**
 * Envíos múltiples: en el paso de envío del checkout nativo
 * - muestra el detalle de la opción elegida (bloque del hook displayBeforeCarrier);
 * - pone en las opciones combinadas el nombre de todos los transportistas, sus plazos y el desglose del precio
 *   (PrestaShop solo enseña el nombre del último con el precio de todos);
 * - da un id único a cada radio: en las opciones combinadas PrestaShop repite el id del último transportista
 *   y al pulsar la etiqueta se marcaba otra opción.
 * Funciona con el tema clásico, Warehouse y Hummingbird. Si el tema es distinto, no hace nada.
 */
(function () {
  'use strict';

  function readOptions() {
    var box = document.getElementById('josramulti');
    if (!box) {
      return null;
    }
    try {
      return JSON.parse(box.getAttribute('data-options') || '{}');
    } catch (e) {
      return null;
    }
  }

  function radios() {
    return document.querySelectorAll('input[type="radio"][name^="delivery_option["]');
  }

  function showSelected() {
    var box = document.getElementById('josramulti');
    if (!box) {
      return;
    }
    var key = '';
    var list = radios();
    for (var i = 0; i < list.length; i++) {
      if (list[i].checked) {
        key = list[i].value;
      }
    }
    var blocks = box.querySelectorAll('.josramulti-option');
    for (var j = 0; j < blocks.length; j++) {
      blocks[j].style.display = blocks[j].getAttribute('data-key') === key ? '' : 'none';
    }
  }

  function setText(container, selectors, text) {
    if (!text) {
      return;
    }
    var el = container.querySelector(selectors);
    if (el) {
      el.textContent = text;
    }
  }

  function fixLabels(options) {
    var list = radios();
    for (var i = 0; i < list.length; i++) {
      var radio = list[i];

      // id único (las combinadas repiten el del último transportista)
      var row = radio.closest('.js-delivery-option, .delivery-option, .delivery-option__item') || radio.parentNode;
      if (radio.id && document.querySelectorAll('[id="' + radio.id + '"]').length > 1) {
        var oldId = radio.id;
        radio.id = 'josramulti_option_' + i;
        var labels = row ? row.querySelectorAll('label[for="' + oldId + '"]') : [];
        for (var l = 0; l < labels.length; l++) {
          labels[l].setAttribute('for', radio.id);
        }
      }

      var data = options[radio.value];
      if (!data || !row || row.getAttribute('data-josramulti') === '1') {
        continue;
      }
      row.setAttribute('data-josramulti', '1');

      setText(row, '.carrier-name, .delivery-option__carrier-name', data.name);
      setText(row, '.carrier-delay, .delivery-option__content', data.delay);

      var price = row.querySelector('.carrier-price, .delivery-option__price');
      if (price && data.breakdown) {
        var small = document.createElement('small');
        small.className = 'josramulti-breakdown';
        small.textContent = data.breakdown;
        price.appendChild(small);
      }
      // Los logos de una opción combinada son solo del último transportista: mejor quitarlo
      var logo = row.querySelector('.carrier-logo img, img.delivery-option__carrier-logo');
      if (logo) {
        logo.style.display = 'none';
      }
    }
  }

  function init() {
    var options = readOptions();
    if (!options) {
      return;
    }
    fixLabels(options);
    showSelected();
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.name && e.target.name.indexOf('delivery_option[') === 0) {
      showSelected();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  if (typeof window.prestashop !== 'undefined' && window.prestashop.on) {
    ['updatedDeliveryForm', 'updatedAddressForm', 'updateCart', 'changedCheckoutStep'].forEach(function (ev) {
      window.prestashop.on(ev, function () {
        setTimeout(init, 50);
      });
    });
  }
})();
