import template from './sw6oidc-provider-detail.html.twig';
import './sw6oidc-provider-detail.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Protocol/token-metadata claims — never useful as an attribute-mapping
 * target (there's no Shopware field they'd sensibly map to). `sub`/
 * `updated_at` deliberately excluded here too even though they're valid
 * OIDC standard claims: `sub` is an opaque per-IdP identifier with nothing
 * to map it to, and `updated_at` is a timestamp, not an identity field.
 *
 * Matched by exact key AND by dot-flattened prefix (`amr.0`, `aud.1`, ...):
 * ClaimsNormalizer::flatten() turns an array-valued claim like
 * `amr: ["pwd", "otp"]` into `amr.0`/`amr.1` — see isTechnicalClaim() below.
 */
const TECHNICAL_CLAIM_EXCLUSIONS = new Set([
    'amr',
    'at_hash',
    'aud',
    'auth_time',
    'azp',
    'exp',
    'iat',
    'iss',
    'jti',
    'nonce',
    'sub',
    'rat',
    'updated_at',
]);

function isTechnicalClaim(key) {
    if (TECHNICAL_CLAIM_EXCLUSIONS.has(key)) {
        return true;
    }

    const dotIndex = key.indexOf('.');

    return dotIndex !== -1 && TECHNICAL_CLAIM_EXCLUSIONS.has(key.slice(0, dotIndex));
}

/**
 * Registered as a lazy factory (matching how Shopware's own core components
 * are registered), not a plain object, so that Mixin.getByName('notification')
 * below is only evaluated once Shopware actually builds this component -
 * never during this plugin's forced-early script execution on the login
 * screen (see Resources/views/administration/index.html.twig), where the
 * "notification" mixin isn't registered yet and this component is never
 * rendered anyway.
 */
Component.register('sw6oidc-provider-detail', () => Promise.resolve({
    template,

    inject: ['repositoryFactory', 'loginService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            provider: null,
            isLoading: true,
            isSaveSuccessful: false,
            isLoadingConfiguration: false,
            isTestingConnection: false,
            connectionTestResult: null,
            isRunningLiveTest: false,
            liveTestReport: null,
            /** @type {Record<string, unknown>} claims received on the last live login test, keyed by claim name */
            liveTestClaims: {},
            // Fixed, not measured: an earlier version tried to measure each
            // "add mapping" button's own rendered width via a $refs lookup
            // and mirror it onto the column, but that never reliably landed
            // (button and column stayed visibly different widths through
            // several rebuilds) - a shared literal both the column width
            // below and the matching button's :style are bound to is less
            // clever but actually renders correctly. Sized generously for
            // each button's own (longer) German label; :style on the button
            // itself guarantees the two always match regardless of exactly
            // how wide the label really needs.
            attributeTypeColumnWidth: '280px',
            mappingTypeColumnWidth: '260px',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier),
        };
    },

    computed: {
        identifier() {
            return this.provider ? (this.provider.displayName || this.provider.appName) : '';
        },

        providerRepository() {
            return this.repositoryFactory.create('sw6oidc_provider');
        },

        /**
         * EntityCollection itself has no `.repository` — a nested
         * association's own repository has to be created explicitly via
         * `entity`/`source` off the collection (Shopware's documented
         * pattern, e.g. `this.product.media.entity`/`.source` in core), then
         * used to `.create()` a new row before `.add()`-ing it to the
         * collection.
         */
        attributeMappingRepository() {
            return this.repositoryFactory.create(this.provider.attributeMappings.entity, this.provider.attributeMappings.source);
        },

        roleMappingRepository() {
            return this.repositoryFactory.create(this.provider.roleMappings.entity, this.provider.roleMappings.source);
        },

        canRunLiveTest() {
            return !!(this.provider && this.provider.id && this.$route.params.id !== undefined);
        },

        attributeTypeOptions() {
            return [
                'email', 'username', 'firstname', 'lastname', 'birthday', 'gender', 'phone',
                'billing_street', 'billing_zipcode', 'billing_city', 'billing_state', 'billing_country', 'billing_phone',
                'shipping_street', 'shipping_zipcode', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_phone',
            ].map((value) => ({ value, label: this.$tc(`sw6oidc.provider.detail.attributeType.${value}`) }));
        },

        mappingTypeOptions() {
            return ['admin_role', 'customer_group', 'superadmin'].map((value) => ({
                value,
                label: this.$tc(`sw6oidc.provider.detail.mappingType.${value}`),
            }));
        },

        loginTypeOptions() {
            return ['both', 'customer', 'admin'].map((value) => ({
                value,
                label: this.$tc(`sw6oidc.provider.detail.loginType.${value}`),
            }));
        },

        pkceFlowOptions() {
            return ['S256', 'plain'].map((value) => ({ value, label: value }));
        },

        claimEncodingOptions() {
            return ['none', 'base64'].map((value) => ({ value, label: value }));
        },

        /**
         * Mirrors AdminProvisioningService::findOrCreateAdmin()'s own
         * refusal condition — warn here, before it fails a real login, that
         * a resolvable ACL role (or an active superadmin group mapping) is
         * required to JIT-create an admin.
         */
        adminProvisioningWarning() {
            if (!this.provider?.autoCreateAdmin || this.provider.defaultAclRoleId) {
                return false;
            }

            const hasRoleMapping = this.provider.roleMappings?.some((mapping) => mapping.mappingType === 'admin_role');
            const hasActiveSuperadminMapping = this.provider.allowSuperadminGroupMapping
                && this.provider.roleMappings?.some((mapping) => mapping.mappingType === 'superadmin');

            return !hasRoleMapping && !hasActiveSuperadminMapping;
        },

        /**
         * Claim names actually received on the most recent live login test —
         * the only source for the attribute-mapping picker (no static
         * "common claims" list): showing a claim this specific IdP doesn't
         * actually send would just be misleading. Empty until a live test
         * has been run at least once. Excludes protocol/token-metadata
         * claims (see TECHNICAL_CLAIM_EXCLUSIONS/isTechnicalClaim) — an
         * IdP's raw response always includes these, but they're never a
         * sensible attribute-mapping target.
         */
        discoveredClaimKeys() {
            return Object.keys(this.liveTestClaims ?? {}).filter((key) => !isTechnicalClaim(key));
        },

        /**
         * sw-single-select's own option shape.
         */
        claimSelectOptions() {
            return this.discoveredClaimKeys.map((key) => ({ value: key, label: key }));
        },
    },

    created() {
        this.createdComponent();
    },

    mounted() {
        window.addEventListener('message', this.onTestResultMessage);
    },

    beforeUnmount() {
        window.removeEventListener('message', this.onTestResultMessage);
    },

    methods: {
        createdComponent() {
            if (this.$route.params.id) {
                this.loadEntity(this.$route.params.id);

                return;
            }

            this.provider = this.providerRepository.create(Shopware.Context.api);
            this.provider.scope = 'openid profile email';
            this.provider.pkceFlow = 'S256';
            this.provider.claimEncoding = 'none';
            this.provider.groupAttribute = 'groups';
            this.provider.loginType = 'both';
            this.provider.isActive = true;
            this.provider.autoCreateCustomer = true;
            this.provider.httpTimeout = 30;
            this.provider.jwksCacheTtl = 86400;
            this.isLoading = false;
        },

        loadEntity(id) {
            this.isLoading = true;
            const criteria = new Criteria();
            criteria.addAssociation('attributeMappings');
            criteria.addAssociation('roleMappings');

            return this.providerRepository.get(id, Shopware.Context.api, criteria).then((entity) => {
                this.provider = entity;
                // Seeds the claim picker from whatever the last live login
                // test actually observed, persisted server-side precisely so
                // it survives a reload — this in-memory state otherwise has
                // nowhere else to come from on a fresh page load.
                this.liveTestClaims = entity.lastTestClaims && typeof entity.lastTestClaims === 'object'
                    ? entity.lastTestClaims
                    : {};
                this.isLoading = false;
            });
        },

        async onClickSave() {
            this.isLoading = true;
            this.isSaveSuccessful = false;

            if (this.provider.wellKnownConfigUrl) {
                // Best-effort re-discovery on every save: apply whatever the
                // IdP returns, but never block the save on it — an admin who
                // intentionally kept manually-entered endpoints shouldn't be
                // locked out of saving just because the well-known URL is
                // temporarily unreachable.
                try {
                    await this.discoverAndApply(this.provider.wellKnownConfigUrl);
                } catch (exception) {
                    this.createNotificationWarning({
                        title: this.$tc('sw6oidc.provider.detail.discoverySaveWarningTitle'),
                        message: exception.message || this.$tc('sw6oidc.provider.detail.discoveryError'),
                    });
                }
            }

            return this.providerRepository.save(this.provider, Shopware.Context.api).then(() => {
                this.isSaveSuccessful = true;
                this.isLoading = false;

                if (this.$route.params.id === undefined) {
                    this.$router.push({ name: 'sw6oidc.provider.detail', params: { id: this.provider.id } });

                    return;
                }

                this.loadEntity(this.provider.id);
            }).catch(() => {
                this.isLoading = false;
                this.createNotificationError({
                    message: this.$tc('sw6oidc.provider.detail.saveError'),
                });
            });
        },

        async onClickLoadConfiguration() {
            this.isLoadingConfiguration = true;

            try {
                const warnings = await this.discoverAndApply(this.provider.wellKnownConfigUrl);
                this.createNotificationSuccess({ message: this.$tc('sw6oidc.provider.detail.discoverySuccess') });

                warnings.forEach((warning) => {
                    this.createNotificationWarning({ message: warning });
                });
            } catch (exception) {
                this.createNotificationError({
                    message: exception.message || this.$tc('sw6oidc.provider.detail.discoveryError'),
                });
            } finally {
                this.isLoadingConfiguration = false;
            }
        },

        /**
         * Fetches the well-known document and applies the returned endpoint
         * fields onto the in-memory (not-yet-saved) provider. Throws with a
         * human-readable message on failure so both callers (the explicit
         * "Load configuration" button and the save-time best-effort refresh)
         * can decide for themselves whether a failure should block anything.
         *
         * @return {Promise<string[]>} warnings returned alongside a successful fetch
         */
        async discoverAndApply(wellKnownConfigUrl) {
            if (!wellKnownConfigUrl) {
                throw new Error(this.$tc('sw6oidc.provider.detail.wellKnownConfigUrlRequired'));
            }

            const response = await this.sw6oidcApiFetch('/api/_action/sw6oidc/provider/discover', {
                wellKnownConfigUrl,
                httpTimeout: this.provider.httpTimeout,
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.message || this.$tc('sw6oidc.provider.detail.discoveryError'));
            }

            const { warnings = [], ...endpoints } = result;

            Object.entries(endpoints).forEach(([key, value]) => {
                if (value) {
                    this.provider[key] = value;
                }
            });

            return warnings;
        },

        async onClickTestConnection() {
            this.isTestingConnection = true;
            this.connectionTestResult = null;

            try {
                const response = await this.sw6oidcApiFetch('/api/_action/sw6oidc/provider/test-connection', {
                    wellKnownConfigUrl: this.provider.wellKnownConfigUrl,
                    authorizeEndpoint: this.provider.authorizeEndpoint,
                    accessTokenEndpoint: this.provider.accessTokenEndpoint,
                    userInfoEndpoint: this.provider.userInfoEndpoint,
                    jwksEndpoint: this.provider.jwksEndpoint,
                    endSessionEndpoint: this.provider.endSessionEndpoint,
                    revocationEndpoint: this.provider.revocationEndpoint,
                    issuer: this.provider.issuer,
                    clientId: this.provider.clientId,
                    clientSecret: this.provider.clientSecret,
                    publicClient: this.provider.publicClient,
                    httpTimeout: this.provider.httpTimeout,
                });

                this.connectionTestResult = await response.json();
            } catch (exception) {
                // eslint-disable-next-line no-console
                console.error('sw6oidc: connection test failed', exception);
                this.createNotificationError({ message: this.$tc('sw6oidc.provider.detail.testConnectionError') });
            } finally {
                this.isTestingConnection = false;
            }
        },

        async onClickRunLiveTest() {
            this.isRunningLiveTest = true;
            this.liveTestReport = null;

            try {
                const response = await this.sw6oidcApiFetch(`/api/_action/sw6oidc/provider/${this.provider.id}/test`, {});
                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.message || this.$tc('sw6oidc.provider.detail.liveTestError'));
                }

                window.open(result.authorizeUrl, 'sw6oidcTest', 'scrollbars=1,width=800,height=600');
            } catch (exception) {
                this.createNotificationError({
                    message: exception.message || this.$tc('sw6oidc.provider.detail.liveTestError'),
                });
            } finally {
                this.isRunningLiveTest = false;
            }
        },

        /**
         * Picks up the pass/fail report the test-callback popup posts back via
         * window.postMessage once the live login test completes, so the
         * detail page can show the outcome inline without the admin having to
         * manually close the popup and reload — reloading the entity also
         * happens here since the popup already persisted lastTestStatus/
         * lastTestAt server-side.
         */
        onTestResultMessage(event) {
            if (event.origin !== window.location.origin || !event.data || event.data.type !== 'sw6oidc-test-result') {
                return;
            }

            this.liveTestReport = event.data;
            this.liveTestClaims = event.data.claims && typeof event.data.claims === 'object' ? event.data.claims : {};

            if (this.provider && this.provider.id) {
                this.loadEntity(this.provider.id);
            }
        },

        sw6oidcApiFetch(path, bodyFields) {
            return fetch(path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Authorization: `Bearer ${this.loginService.getToken()}`,
                },
                body: new URLSearchParams(
                    Object.fromEntries(Object.entries(bodyFields).filter(([, value]) => value !== null && value !== undefined)),
                ),
            });
        },

        onAddAttributeMapping() {
            const mapping = this.attributeMappingRepository.create(Shopware.Context.api);
            mapping.providerId = this.provider.id;
            mapping.attributeType = 'email';
            mapping.attributeName = '';
            mapping.syncOnSso = false;
            this.provider.attributeMappings.add(mapping);
        },

        onRemoveAttributeMapping(item) {
            this.provider.attributeMappings.remove(item.id);
        },

        onAddRoleMapping() {
            const mapping = this.roleMappingRepository.create(Shopware.Context.api);
            mapping.providerId = this.provider.id;
            mapping.mappingType = 'customer_group';
            mapping.oidcGroup = '';
            mapping.aclRoleId = null;
            mapping.customerGroupId = null;
            mapping.sortOrder = this.provider.roleMappings.length;
            this.provider.roleMappings.add(mapping);
        },

        onRemoveRoleMapping(item) {
            this.provider.roleMappings.remove(item.id);
        },

        /**
         * Clears whichever target FK no longer applies when a row switches
         * mapping type — otherwise a stale aclRoleId/customerGroupId from
         * before the switch would still get saved even though its picker is
         * no longer shown. 'superadmin' rows need neither target at all.
         */
        onMappingTypeChange(item) {
            if (item.mappingType === 'admin_role') {
                item.customerGroupId = null;
            } else if (item.mappingType === 'customer_group') {
                item.aclRoleId = null;
            } else {
                item.aclRoleId = null;
                item.customerGroupId = null;
            }
        },
    },
}));
