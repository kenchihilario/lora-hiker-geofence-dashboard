(function (root, factory) {
  const api = factory();
  if (root) root.TrailwatchSourceFlags = api;
  if (typeof module === 'object' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : null, function () {
  function labels(rows) {
    const found = new Set();
    for (const row of rows || []) {
      if (Number(row.is_demo) === 1) found.add('DEMO');
      if (Number(row.is_rehearsal) === 1) found.add('REHEARSAL');
      if (Number(row.test_mode) === 1) found.add('TEST');
      if (Number(row.is_bench) === 1) found.add('BENCH');
    }
    return [...found];
  }
  return { labels };
});
