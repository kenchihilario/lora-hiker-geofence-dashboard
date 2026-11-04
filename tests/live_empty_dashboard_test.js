const fs = require('node:fs');
const vm = require('node:vm');
const {layout}=require('../public/assets/js/dashboard-label-layout.js');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/dashboard.js'), 'utf8');
const start = source.indexOf('  function drawZone(');
if (start < 0) throw new Error('drawZone was not found');
const open = source.indexOf('{', start);
let depth = 0, end = -1;
for (let i = open; i < source.length; i++) {
  if (source[i] === '{') depth++;
  if (source[i] === '}' && --depth === 0) { end = i + 1; break; }
}
if (end < 0) throw new Error('drawZone function did not close');
const elements = new Map();
const element = () => ({ innerHTML: '', textContent: '', attributes: {}, setAttribute(k, v) { this.attributes[k] = v; } });
const polygon = element(), markers = element(), refs = element(), grid = element(), north = element(), scaleLabel = element();
const svg = { attributes: {}, style:{values:{},setProperty(k,v){this.values[k]=v;}},getBoundingClientRect(){return{width:400,height:235};},setAttribute(k, v) { this.attributes[k] = v; }, querySelector(sel) { return ({ '#api-zone': polygon, '#api-markers': markers, '#api-reference-points': refs, '#map-grid': grid, '#map-north-scale': north })[sel] || null; } };
elements.set('.trail-map', svg); elements.set('#map-scale-label', scaleLabel);
const context = { TrailwatchMapLabels:{layout}, $: selector => elements.get(selector) || null, esc: value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]), timeAgo: seconds => seconds == null ? 'No report yet' : `${seconds} sec ago` };
const drawZone = vm.runInNewContext(`(${source.slice(start, end).trim()})`, context);

drawZone(null, [], [], [], { name: 'Mt. Balagbag', center: { latitude: 14.8243087, longitude: 121.1804131 } });
if (!svg.attributes.viewBox || !scaleLabel.textContent.includes('no safe zone set')) throw new Error('empty safe-zone response did not render a local metric frame');
if (polygon.attributes.points !== '') throw new Error('missing safe zone unexpectedly rendered a polygon');

drawZone(null, [{ node_id: 'H1', latitude: null, longitude: null }], [], [], { name: 'Mt. Balagbag', center: null });
if (markers.innerHTML !== '' || scaleLabel.textContent !== 'Local metric view · no positioned records') throw new Error('null coordinates were treated as a position');

drawZone(null, [{ node_id: 'H1', latitude: 14.825, longitude: 121.181, received_at: '2026-11-04 10:00:00', seconds_since_report: 20, link_state: 'LINK_LOST' }], [], [], { name: 'Mt. Balagbag', center: null });
if (!markers.innerHTML.includes('H1') || !markers.innerHTML.includes('Last report') || svg.attributes.viewBox !== '0 0 800 470') throw new Error('single link-lost position did not render with stable scale and time');
if (!markers.innerHTML.includes('font-size:22px') || svg.style.values['--svg-css-scale'] !== '0.5') throw new Error('single marker labels did not counter-scale to 11 CSS pixels');
if (!source.includes('LIVE · API OFFLINE') || !source.includes('No demo data is substituted')) throw new Error('LIVE API error state could imply demo fallback');
console.log('PASS live empty dashboard: null zone, no coordinates, and single link-lost point');
