/**
 * Puente entre el private content de Magento y el widget de Mindo.
 *
 * Lee la section `mindo-identity` —que Magento sirve por
 * `/customer/section/load` y nunca cachea— y se la pasa al widget por su API
 * de runtime. El JWT nunca toca el HTML, así que el Full Page Cache no puede
 * mezclarlo entre visitantes.
 *
 * @copyright Mindo Software
 */
define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    var SECTION = 'mindo-identity';

    /**
     * Llama al widget, esté o no cargado todavía.
     *
     * widget.js captura `window.Mindo.q` cuando arranca y la drena al montar;
     * después de eso la cola ya no se lee y hay que llamar al método directo.
     * Los dos caminos existen porque el orden de carga no está garantizado:
     * el loader es `async`.
     */
    function call(method, arg) {
        window.Mindo = window.Mindo || { q: [] };

        if (typeof window.Mindo[method] === 'function') {
            window.Mindo[method](arg);

            return;
        }

        window.Mindo.q = window.Mindo.q || [];
        window.Mindo.q.push(arg === undefined ? [method] : [method, arg]);
    }

    return function () {
        var section = customerData.get(SECTION),
            last;

        function apply(data) {
            // Sin la clave `identity` la section todavía no cargó. Distinto de
            // `identity: null`, que sí significa "no hay nadie logueado".
            if (!data || !Object.prototype.hasOwnProperty.call(data, 'identity')) {
                return;
            }

            var jwt = data.identity || null;

            if (jwt === last) {
                return;
            }

            last = jwt;

            if (jwt) {
                call('setUser', jwt);
            } else {
                call('clearUser');
            }
        }

        // El subscribe solo dispara con cambios posteriores: el valor que ya
        // está en el storage del browser hay que leerlo a mano.
        apply(section());
        section.subscribe(apply);
    };
});
