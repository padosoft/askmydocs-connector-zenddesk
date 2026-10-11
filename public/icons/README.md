# Zendesk icon

`zendesk.svg` is an original, editorial interpretation of the Zendesk mark drawn for the AskMyDocs connector family: two mirrored "Z" halves on the Zendesk signature dark-teal background. It is **not** the official asset. The official brand resources live at <https://www.zendesk.com/company/brand-assets/>.

The service provider publishes the icon to the host's `public/connectors/zendesk.svg` (`php artisan vendor:publish --tag=connector-zendesk-assets`), which is what `ZendeskConnector::iconUrl()` resolves through `asset()`. It is served locally; nothing is loaded from Zendesk at runtime.

Zendesk is a trademark of Zendesk, Inc. and is used here only to identify the integration. The Apache-2.0 licence of this package does not grant any rights to the trademark.
