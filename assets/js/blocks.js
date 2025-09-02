(function() {
    const registry = window.wc && window.wc.wcBlocksRegistry ? window.wc.wcBlocksRegistry : null;
    const data = window.SWAPPED_PAY_BLOCKS_DATA || {};
    if (!registry || typeof registry.registerPaymentMethod !== 'function') return;
    const canMakePayment = () => data.enabled === 'yes';
    registry.registerPaymentMethod({
        name: data.slug || 'swapped-pay',
        label: wp.element.createElement('span', null, data.title || 'Swapped Pay (Crypto)'),
        content: wp.element.createElement('div', null, data.description || 'Pay with crypto via Swapped.'),
        edit: wp.element.createElement('div', null, data.description || 'Pay with crypto via Swapped.'),
        canMakePayment,
        ariaLabel: data.title || 'Swapped Pay',
        supports: { features: ['products'] },
        paymentMethodId: data.slug || 'swapped-pay'
    });
})();
