// Logique pure : tailles de montre, tri du catalogue, remises lot
export const WATCH_SIZES = [
  { key: "38", label: "38 mm", n: "38", g: "A" },
  { key: "40", label: "40 mm", n: "40", g: "A" },
  { key: "41", label: "41 mm", n: "41", g: "A" },
  { key: "42", label: "42 mm", n: "42", g: "A" },
  { key: "42s10", label: "42 mm série 10", n: "42", g: "B" },
  { key: "44", label: "44 mm", n: "44", g: "B" },
  { key: "45", label: "45 mm", n: "45", g: "B" },
  { key: "46", label: "46 mm", n: "46", g: "B" },
  { key: "49", label: "49 mm", n: "49", g: "B" },
];

export const nums = (s) => (String(s || "").match(/\d+/g) || []).filter((v, i, a) => a.indexOf(v) === i);
const plain = (t) => String(t || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

export function kindOf(p) {
  const hay = plain((p.name || "") + " " + (p.categories || []).map((c) => c.name + " " + c.slug).join(" "));
  if (/antichoc|anti-choc|anti choc/.test(hay)) return "antichoc";
  if (/coque/.test(hay)) return "coque";
  if (/chargeur|accessoire/.test(hay)) return "accessoire";
  return "bracelet";
}
export const isAccessory = (p) => kindOf(p) !== "bracelet";

export function price(p) {
  const pr = p.prices || {};
  const minor = pr.currency_minor_unit == null ? 0 : pr.currency_minor_unit;
  return Math.round(parseInt(pr.price || "0", 10) / Math.pow(10, minor));
}

// Options de taille d'un produit : [{label, value, id}] (value = valeur envoyée au panier)
export function sizeOptions(p) {
  const out = [];
  (p.variations || []).forEach((v) => {
    const a = (v.attributes || [])[0];
    if (a) out.push({ id: v.id, value: a.value, name: a.name, label: String(a.value).replace(/-/g, " ") });
  });
  if (!out.length) {
    (p.attributes || []).forEach((at) => (at.terms || []).forEach((t) => out.push({ id: null, value: t.name, name: at.name, label: t.name })));
  }
  return out;
}

export function optionMatches(label, size) {
  const n = nums(label);
  if (!n.length) return false;
  if (n.length > 1) return size.g === "A" ? n.includes("38") : n.includes("44");
  return n[0] === size.n;
}

// Produits compatibles avec la taille de montre choisie (et en stock)
export function compatible(products, size) {
  return products
    .filter((p) => p.is_in_stock !== false)
    .map((p) => {
      const opts = sizeOptions(p);
      if (!opts.length) return { p, opts: [] };
      const ok = opts.filter((o) => optionMatches(o.label, size));
      return ok.length ? { p, opts: ok } : null;
    })
    .filter(Boolean);
}

export function sections(items) {
  const s = { b5: [], b10: [], coque: [], antichoc: [], accessoire: [] };
  items.forEach((it) => {
    const k = kindOf(it.p);
    if (k === "bracelet") (price(it.p) >= 10000 ? s.b10 : s.b5).push(it);
    else s[k].push(it);
  });
  const by = (a, b) => String(a.p.name).localeCompare(String(b.p.name), "fr");
  Object.keys(s).forEach((k) => s[k].sort(by));
  return [
    { key: "b5", title: "Bracelets 5 000 F", sub: promoOn() ? "1 bracelet acheté = 1 offert" : "5 000 F l'unité · 3 bracelets pour 10 000 F", items: s.b5 },
    { key: "b10", title: "Bracelets 10 000 F", sub: promoOn() ? "1 bracelet acheté = 1 offert" : "10 000 F l'unité · 2 bracelets pour 15 000 F", items: s.b10 },
    { key: "coque", title: "Coques", sub: "", items: s.coque },
    { key: "antichoc", title: "Antichocs", sub: "", items: s.antichoc },
    { key: "accessoire", title: "Autres accessoires", sub: "", items: s.accessoire },
  ].filter((x) => x.items.length);
}

// Remise lot : 3 bracelets à 5 000 F = 10 000 F ; 2 bracelets à 10 000 F = 15 000 F (accessoires exclus)
// Offre novembre 2026 : 1 bracelet acheté = 1 bracelet offert (même prix)
export function promoOn(d) {
  const day = d || new Date().toISOString().slice(0, 10);
  return day >= "2026-11-01" && day <= "2026-11-30";
}
export function cartDiscount(lines) {
  let n5 = 0, n10 = 0;
  (lines || []).forEach((l) => {
    if (l.accessory) return;
    if (l.price === 5000) n5 += l.qty;
    else if (l.price === 10000) n10 += l.qty;
  });
  if (promoOn()) return Math.floor(n5 / 2) * 5000 + Math.floor(n10 / 2) * 10000;
  return Math.floor(n5 / 3) * 5000 + Math.floor(n10 / 2) * 5000;
}
export const cartSubtotal = (lines) => (lines || []).reduce((s, l) => s + l.price * l.qty, 0);
export const cartCount = (lines) => (lines || []).reduce((s, l) => s + l.qty, 0);
export const fmt = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " F";
