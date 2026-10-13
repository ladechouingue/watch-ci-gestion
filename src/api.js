// Client WooCommerce Store API (public, sans clé)
import { STORE } from "./config";

async function call(path, { method = "GET", body, token, nonce } = {}) {
  const headers = { Accept: "application/json" };
  if (body) headers["Content-Type"] = "application/json";
  if (token) headers["Cart-Token"] = token;
  if (nonce) headers["Nonce"] = nonce;
  const res = await fetch(STORE + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
  let data = null;
  try { data = await res.json(); } catch (e) {}
  if (!res.ok) {
    const msg = (data && (data.message || (data.data && data.data.message))) || "Erreur " + res.status;
    throw new Error(String(msg).replace(/<[^>]+>/g, ""));
  }
  return { data, token: res.headers.get("Cart-Token") || token, nonce: res.headers.get("Nonce") || nonce };
}

export async function loadProducts() {
  let all = [];
  for (let page = 1; page <= 10; page++) {
    const r = await call("/products?per_page=100&page=" + page);
    all = all.concat(r.data || []);
    if (!r.data || r.data.length < 100) break;
  }
  return all;
}

// Envoie le panier local au site puis passe la commande (paiement à la livraison)
export async function placeOrder(lines, c) {
  let st = { token: null, nonce: null };
  for (const l of lines) {
    const body = { id: l.id, quantity: l.qty };
    if (l.attr) body.variation = [{ attribute: l.attr, value: l.value }];
    const r = await call("/cart/add-item", { method: "POST", body, token: st.token, nonce: st.nonce });
    st = { token: r.token, nonce: r.nonce };
  }
  const addr = {
    first_name: c.name, last_name: "-", address_1: c.address, city: c.city || "Abidjan",
    state: "", postcode: "", country: "CI", phone: c.phone,
    email: c.email || c.phone.replace(/\D/g, "") + "@client.watchcotedivoire.com",
  };
  let r = await call("/cart/update-customer", { method: "POST", body: { billing_address: addr, shipping_address: addr }, token: st.token, nonce: st.nonce });
  st = { token: r.token, nonce: r.nonce };
  const pk = ((r.data && r.data.shipping_rates) || [])[0];
  const rates = (pk && pk.shipping_rates) || [];
  if (rates.length) {
    const pick = rates.find((x) => x.selected) || rates[0];
    r = await call("/cart/select-shipping-rate", { method: "POST", body: { package_id: pk.package_id || 0, rate_id: pick.rate_id }, token: st.token, nonce: st.nonce });
    st = { token: r.token, nonce: r.nonce };
  }
  const o = await call("/checkout", {
    method: "POST", token: st.token, nonce: st.nonce,
    body: { billing_address: addr, shipping_address: addr, payment_method: "cod", customer_note: c.note || "", payment_data: [] },
  });
  return o.data; // {order_id, order_number, status...}
}
