(function () {
  const registry = window.wc && window.wc.wcBlocksRegistry ? window.wc.wcBlocksRegistry : null;
  const data = window.SWAPPED_COMMERCE_BLOCKS_DATA || {};
  if (!registry || typeof registry.registerPaymentMethod !== 'function') return;

  const el = wp.element.createElement;

  const IconRow = () => {
    const imgStyle = { height: 20, verticalAlign: 'text-bottom', marginLeft: 2, marginRight: 2 };
    return el('span', { style: { display: 'inline-flex', alignItems: 'center', marginLeft: 6 } }, [
      data?.icons?.btc  ? el('img', { src: data.icons.btc,  alt: 'BTC',  style: imgStyle }) : null,
      data?.icons?.eth  ? el('img', { src: data.icons.eth,  alt: 'ETH',  style: imgStyle }) : null,
      data?.icons?.usdt ? el('img', { src: data.icons.usdt, alt: 'USDT', style: imgStyle }) : null,
      el('span', { style: { fontSize: 13, opacity: 0.8, marginLeft: 3 } }, data.moreText || '(+15 more)')
    ]);
  };

  const Label = () =>
    el('span', { style: { display: 'inline-flex', alignItems: 'center' } }, [
      el('span', null, data.title || 'Swapped Commerce (Crypto)'),
      el(IconRow)
    ]);

  const Content = () =>
    el('div', null, data.description || 'Pay with crypto via Swapped.');

  registry.registerPaymentMethod({
    name: data.slug || 'swapped-commerce',
    label: el(Label),
    content: el(Content),
    edit: el(Content),
    canMakePayment: () => data.enabled === 'yes',
    ariaLabel: data.title || 'Swapped Commerce',
    supports: { features: ['products'] },
    paymentMethodId: data.slug || 'swapped-commerce'
  });
})();