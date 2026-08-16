# Mindo_ChatWidget

Widget de chat web de Mindo para Magento 2, con identidad firmada del cliente logueado.

- Inyecta el widget en el frontend — sin pegar snippets en el admin.
- Firma un **JWT HS256** con el cliente logueado (`sub`, `name`, `email`, `phone`) para que el agente sepa con quién habla y el chat se una al contacto que ya existe en el CRM.
- Entrega ese JWT por **private content**, no por HTML: el Full Page Cache nunca lo ve.

## Requisitos

| | |
|---|---|
| Magento | 2.4.x (Adobe Commerce y Open Source) |
| PHP | 8.1 – 8.4 |
| Canal | Un canal Web creado en MINDO (Configuración → Canales → Widget de chat web) |

Del canal necesitás dos cosas: el **token** (público, va en el HTML) y el **secreto HMAC** (privado, firma la identidad).

## Instalación

```bash
composer config repositories.mindo-chat-widget vcs git@github.com:<ORG>/magento-module-chat-widget.git
composer require mindo/module-chat-widget

bin/magento module:enable Mindo_ChatWidget
bin/magento setup:upgrade
bin/magento setup:di:compile        # solo en modo producción
bin/magento cache:flush
```

El repo es privado: la máquina que corra `composer` necesita acceso de lectura (deploy key en el repo, o el token de GitHub en `auth.json`).

## Configuración

**Stores → Configuration → Mindo → Chat Widget**

| Campo | Qué es |
|---|---|
| Habilitado | Inyecta el widget en ese store view |
| Token del canal | El `public_id` del canal Web |
| Secreto HMAC | El secreto del **mismo** canal. Se guarda encriptado |
| Vigencia de la identidad | Default 86400 (24 h). Mindo rechaza más de 7 días |
| URL del loader | Default `https://app.mindosoftware.com/widget.js` |

El secreto está marcado como sensible, así que también podés cargarlo por entorno y dejarlo fuera de la base y de cualquier `config:dump`:

```bash
bin/magento config:sensitive:set mindo_chat_widget/general/hmac_secret 'EL_SECRETO'
```

Los cinco campos son por store view: podés tener un canal distinto por tienda.

### Antes de probar

Si el canal tiene `allowed_origins` cargado en Mindo, **agregá el dominio de la tienda** (incluido el de staging). Con la lista vacía no hay restricción de origen.

## Verificar

Sin Magento, para cerrar la mitad criptográfica:

```bash
php tools/sign-test.php 'EL_SECRETO' 'EL_TOKEN' 'https://tu-tienda.com'
```

`200` = Mindo aceptó la firma. `401` = el secreto no es el del canal del token.

Ya instalado, con un cliente logueado en la tienda:

1. `/customer/section/load?sections=mindo-identity` devuelve `{"mindo-identity":{"identity":"eyJ..."}}`.
2. En la consola, `window.Mindo` existe y el chat abre.
3. En Mindo, el chat entra asociado al contacto — no como visitante anónimo.
4. Logout → recargar → la section devuelve `identity: null`.

## Cómo funciona

```
HTML (cacheado por FPC)          →  <script widget.js data-mindo-token="...">
                                    identico para todos, sin datos privados

/customer/section/load           →  mindo-identity: { identity: "<JWT>" }
(private content, sin cache)        MindoIdentity.php lo firma por request

identity.js                      →  window.Mindo.setUser(jwt)
                                    del navegador al widget, nunca por el HTML
```

Renderizar el JWT en el `.phtml` sería el bug clásico de Magento: el FPC guarda la página del primer cliente y se la sirve al resto, mezclando conversaciones. Las sections de private content existen exactamente para esto.

**El JWT que se firma:**

```json
{ "sub": "88213", "name": "Juan Pérez", "email": "juan@x.com", "phone": "+5491122334455", "exp": 1760000000 }
```

`sub` es el customer ID de Magento — estable, a diferencia del email. El resto es opcional: Mindo los usa para completar y unificar el contacto.

**Degradación:** ante cualquier problema —secreto mal cargado, JWT vencido, firma inválida— el visitante chatea **anónimo**. Ni el widget ni el private content de la tienda se rompen.

## Problemas frecuentes

| Síntoma | Causa |
|---|---|
| No aparece la burbuja | `Habilitado` en No, falta el token, o la URL del loader no es `https://` |
| Aparece pero todos anónimos | Falta el secreto, o es de otro canal |
| `403` en el ingest | El dominio no está en `allowed_origins` del canal |
| Identifica y a las horas deja de hacerlo | `exp` menor que el lifetime de las sections: subí la vigencia |
| Cambié la config y no pasa nada | `bin/magento cache:flush` |

## Licencia

Propietaria — Mindo Software. Uso restringido a clientes con contrato vigente.
