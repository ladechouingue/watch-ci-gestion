import React, { useEffect, useMemo, useState } from "react";
import {
  View, Text, Image, ScrollView, TouchableOpacity, TextInput, ActivityIndicator,
  Linking, Alert, SafeAreaView, StyleSheet, Platform, StatusBar as RNStatusBar,
} from "react-native";
import { StatusBar } from "expo-status-bar";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { ORANGE, DARK, WHATSAPP } from "./src/config";
import { loadProducts, placeOrder } from "./src/api";
import {
  WATCH_SIZES, compatible, sections, price, cartDiscount, cartSubtotal, cartCount, fmt, isAccessory,
} from "./src/logic";

const logo = require("./assets/logo.png");

export default function App() {
  const [products, setProducts] = useState(null);
  const [err, setErr] = useState("");
  const [size, setSize] = useState(null);
  const [cart, setCart] = useState([]);
  const [screen, setScreen] = useState("home"); // home | shop | cart | done
  const [pick, setPick] = useState(null); // produit ouvert
  const [ready, setReady] = useState(false);
  const [done, setDone] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const s = await AsyncStorage.getItem("size");
        const c = await AsyncStorage.getItem("cart");
        if (s) { setSize(WATCH_SIZES.find((x) => x.key === s) || null); setScreen("shop"); }
        if (c) setCart(JSON.parse(c));
      } catch (e) {}
      setReady(true);
    })();
    load();
  }, []);
  useEffect(() => { if (ready) AsyncStorage.setItem("cart", JSON.stringify(cart)).catch(() => {}); }, [cart, ready]);

  async function load() {
    setErr(""); setProducts(null);
    try { setProducts(await loadProducts()); } catch (e) { setErr(e.message || "Connexion impossible"); }
  }
  function chooseSize(s) {
    setSize(s); setScreen("shop"); AsyncStorage.setItem("size", s.key).catch(() => {});
  }
  function add(p, opt) {
    const key = p.id + "|" + (opt ? opt.value : "");
    setCart((c) => {
      const i = c.findIndex((l) => l.key === key);
      if (i >= 0) return c.map((l, j) => (j === i ? { ...l, qty: l.qty + 1 } : l));
      return [...c, {
        key, id: p.id, name: p.name, img: p.images && p.images[0] && p.images[0].thumbnail,
        label: opt ? opt.label : "", attr: opt ? opt.name : null, value: opt ? opt.value : null,
        price: price(p), qty: 1, accessory: isAccessory(p),
      }];
    });
    setPick(null);
  }
  const qty = (key, d) => setCart((c) => c.map((l) => (l.key === key ? { ...l, qty: l.qty + d } : l)).filter((l) => l.qty > 0));

  const items = useMemo(() => (products && size ? compatible(products, size) : []), [products, size]);
  const secs = useMemo(() => sections(items), [items]);
  const n = cartCount(cart);

  if (!ready) return <View style={s.center}><ActivityIndicator color={ORANGE} /></View>;

  return (
    <SafeAreaView style={s.root}>
      <StatusBar style="dark" />
      {screen === "home" && <Home onPick={chooseSize} />}
      {screen === "shop" && (
        <Shop size={size} secs={secs} loading={!products && !err} err={err} retry={load}
          onSize={() => setScreen("home")} onOpen={setPick} n={n} onCart={() => setScreen("cart")} />
      )}
      {screen === "cart" && (
        <Cart cart={cart} qty={qty} back={() => setScreen("shop")}
          onDone={(r) => { setDone(r); setCart([]); setScreen("done"); }} />
      )}
      {screen === "done" && <Done r={done} onHome={() => setScreen("shop")} />}
      {pick && <Detail it={pick} onClose={() => setPick(null)} onAdd={add} />}
    </SafeAreaView>
  );
}

function Home({ onPick }) {
  const [open, setOpen] = useState(false);
  return (
    <ScrollView contentContainerStyle={{ padding: 20, alignItems: "center" }} keyboardShouldPersistTaps="handled">
      <Image source={logo} style={{ width: 240, height: 120, resizeMode: "contain", marginTop: 30 }} />
      <Text style={s.h1}>Quelle est la taille de ta montre ?</Text>
      <Text style={s.sub}>On te montre seulement ce qui va avec.</Text>
      <View style={{ width: "100%", maxWidth: 420 }}>
        <TouchableOpacity style={s.drop} onPress={() => setOpen(!open)} activeOpacity={0.8}>
          <Text style={s.dropTxt}>Choisis ta taille</Text>
          <Text style={s.dropArrow}>{open ? "▲" : "▼"}</Text>
        </TouchableOpacity>
        {open && (
          <View style={s.list}>
            {WATCH_SIZES.map((x, i) => (
              <TouchableOpacity key={x.key} style={[s.row, i === WATCH_SIZES.length - 1 && { borderBottomWidth: 0 }]} onPress={() => onPick(x)}>
                <Text style={s.rowTxt}>{x.label}</Text>
                <Text style={{ color: ORANGE, fontSize: 18 }}>›</Text>
              </TouchableOpacity>
            ))}
          </View>
        )}
      </View>
    </ScrollView>
  );
}

function Shop({ size, secs, loading, err, retry, onSize, onOpen, n, onCart }) {
  return (
    <View style={{ flex: 1 }}>
      <View style={s.top}>
        <TouchableOpacity onPress={onSize} style={s.chip}><Text style={s.chipTxt}>⌚ {size ? size.label : ""} ▾</Text></TouchableOpacity>
        <TouchableOpacity onPress={() => Linking.openURL("https://wa.me/" + WHATSAPP)} style={s.wa}><Text style={{ color: "#fff", fontWeight: "700" }}>WhatsApp</Text></TouchableOpacity>
        <TouchableOpacity onPress={onCart} style={s.cartBtn}><Text style={{ color: "#fff", fontWeight: "700" }}>🛒 {n}</Text></TouchableOpacity>
      </View>
      {loading && <View style={s.center}><ActivityIndicator size="large" color={ORANGE} /></View>}
      {!!err && (
        <View style={s.center}>
          <Text style={{ textAlign: "center", marginBottom: 12 }}>Connexion impossible{"\n"}{err}</Text>
          <TouchableOpacity style={s.btn} onPress={retry}><Text style={s.btnTxt}>Réessayer</Text></TouchableOpacity>
        </View>
      )}
      {!loading && !err && (
        <ScrollView contentContainerStyle={{ padding: 12, paddingBottom: 40 }}>
          {!secs.length && <Text style={{ textAlign: "center", marginTop: 40, color: "#666" }}>Rien de disponible pour cette taille pour le moment.</Text>}
          {secs.map((sec) => (
            <View key={sec.key} style={{ marginBottom: 18 }}>
              <Text style={s.secT}>{sec.title}</Text>
              {!!sec.sub && <Text style={s.secS}>{sec.sub}</Text>}
              <View style={s.pgrid}>
                {sec.items.map((it) => (
                  <TouchableOpacity key={it.p.id} style={s.card} onPress={() => onOpen(it)}>
                    {it.p.images && it.p.images[0] ? <Image source={{ uri: it.p.images[0].thumbnail || it.p.images[0].src }} style={s.cardImg} /> : <View style={s.cardImg} />}
                    <Text numberOfLines={2} style={s.cardName}>{it.p.name}</Text>
                    <Text style={s.cardPrice}>{fmt(price(it.p))}</Text>
                  </TouchableOpacity>
                ))}
              </View>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
}

function Detail({ it, onClose, onAdd }) {
  const { p, opts } = it;
  const [sel, setSel] = useState(opts.length === 1 ? opts[0] : null);
  const img = p.images && p.images[0] && p.images[0].src;
  return (
    <View style={s.modal}>
      <View style={s.sheet}>
        {!!img && <Image source={{ uri: img }} style={{ width: "100%", height: 260, borderRadius: 12 }} resizeMode="cover" />}
        <Text style={s.h2}>{p.name}</Text>
        <Text style={s.cardPrice}>{fmt(price(p))}</Text>
        {opts.length > 1 && (
          <View style={{ flexDirection: "row", flexWrap: "wrap", marginVertical: 10 }}>
            {opts.map((o) => (
              <TouchableOpacity key={o.value} onPress={() => setSel(o)} style={[s.opt, sel && sel.value === o.value && s.optOn]}>
                <Text style={{ color: sel && sel.value === o.value ? "#fff" : DARK }}>{o.label}</Text>
              </TouchableOpacity>
            ))}
          </View>
        )}
        <TouchableOpacity style={[s.btn, opts.length > 1 && !sel && { opacity: 0.4 }]} disabled={opts.length > 1 && !sel}
          onPress={() => onAdd(p, sel || opts[0] || null)}>
          <Text style={s.btnTxt}>Ajouter au panier</Text>
        </TouchableOpacity>
        <TouchableOpacity onPress={onClose} style={{ padding: 14 }}><Text style={{ textAlign: "center", color: "#666" }}>Fermer</Text></TouchableOpacity>
      </View>
    </View>
  );
}

function Cart({ cart, qty, back, onDone }) {
  const [f, setF] = useState({ name: "", phone: "", address: "", city: "Abidjan", email: "", note: "" });
  const [busy, setBusy] = useState(false);
  const sub = cartSubtotal(cart), disc = cartDiscount(cart);
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
  async function go() {
    if (!f.name.trim() || !f.phone.trim() || !f.address.trim()) { Alert.alert("Il manque des infos", "Nom, téléphone et adresse de livraison sont obligatoires."); return; }
    setBusy(true);
    try { onDone(await placeOrder(cart, f)); }
    catch (e) { Alert.alert("Commande impossible", e.message || "Réessaie ou écris-nous sur WhatsApp."); }
    setBusy(false);
  }
  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 60 }} keyboardShouldPersistTaps="handled">
      <TouchableOpacity onPress={back}><Text style={{ color: ORANGE, fontWeight: "700" }}>← Continuer mes achats</Text></TouchableOpacity>
      <Text style={s.h2}>Mon panier</Text>
      {!cart.length && <Text style={{ color: "#666", marginVertical: 20 }}>Ton panier est vide.</Text>}
      {cart.map((l) => (
        <View key={l.key} style={s.line}>
          {l.img ? <Image source={{ uri: l.img }} style={{ width: 56, height: 56, borderRadius: 8 }} /> : <View style={{ width: 56, height: 56 }} />}
          <View style={{ flex: 1, marginHorizontal: 10 }}>
            <Text numberOfLines={2} style={{ fontWeight: "600" }}>{l.name}</Text>
            <Text style={{ color: "#666" }}>{l.label ? l.label + " · " : ""}{fmt(l.price)}</Text>
          </View>
          <TouchableOpacity onPress={() => qty(l.key, -1)} style={s.q}><Text>−</Text></TouchableOpacity>
          <Text style={{ width: 24, textAlign: "center" }}>{l.qty}</Text>
          <TouchableOpacity onPress={() => qty(l.key, 1)} style={s.q}><Text>+</Text></TouchableOpacity>
        </View>
      ))}
      {!!cart.length && (
        <>
          <View style={s.tot}>
            <Row a="Sous-total" b={fmt(sub)} />
            {disc > 0 && <Row a="Remise lot" b={"− " + fmt(disc)} green />}
            <Row a="Total (hors livraison)" b={fmt(sub - disc)} bold />
            <Text style={{ color: "#666", marginTop: 6, fontSize: 12 }}>Livraison calculée à la commande · paiement à la livraison</Text>
          </View>
          <Text style={s.h2}>Livraison</Text>
          <Field l="Nom complet *" v={f.name} on={set("name")} />
          <Field l="Téléphone *" v={f.phone} on={set("phone")} kb="phone-pad" />
          <Field l="Adresse / quartier *" v={f.address} on={set("address")} />
          <Field l="Ville" v={f.city} on={set("city")} />
          <Field l="Email (facultatif)" v={f.email} on={set("email")} kb="email-address" />
          <Field l="Note (facultatif)" v={f.note} on={set("note")} />
          <TouchableOpacity style={[s.btn, busy && { opacity: 0.5 }]} disabled={busy} onPress={go}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={s.btnTxt}>Commander</Text>}
          </TouchableOpacity>
        </>
      )}
    </ScrollView>
  );
}
const Row = ({ a, b, bold, green }) => (
  <View style={{ flexDirection: "row", justifyContent: "space-between", marginVertical: 2 }}>
    <Text style={{ fontWeight: bold ? "800" : "400" }}>{a}</Text>
    <Text style={{ fontWeight: bold ? "800" : "400", color: green ? "#1a7f37" : DARK }}>{b}</Text>
  </View>
);
const Field = ({ l, v, on, kb }) => (
  <View style={{ marginTop: 10 }}>
    <Text style={{ color: "#555", marginBottom: 4 }}>{l}</Text>
    <TextInput value={v} onChangeText={on} keyboardType={kb} autoCapitalize={kb ? "none" : "sentences"} style={s.input} />
  </View>
);

function Done({ r, onHome }) {
  return (
    <View style={s.center}>
      <Text style={{ fontSize: 56 }}>✅</Text>
      <Text style={s.h1}>Commande envoyée !</Text>
      <Text style={{ textAlign: "center", marginBottom: 20 }}>Commande n° {r && (r.order_number || r.order_id)}{"\n"}On te contacte très vite pour la livraison.</Text>
      <TouchableOpacity style={s.btn} onPress={onHome}><Text style={s.btnTxt}>Retour à la boutique</Text></TouchableOpacity>
    </View>
  );
}

const s = StyleSheet.create({
  root: { flex: 1, backgroundColor: "#fff", paddingTop: Platform.OS === "android" ? RNStatusBar.currentHeight : 0 },
  center: { flex: 1, alignItems: "center", justifyContent: "center", padding: 24 },
  h1: { fontSize: 22, fontWeight: "800", color: DARK, textAlign: "center", marginTop: 24 },
  h2: { fontSize: 18, fontWeight: "800", color: DARK, marginTop: 16, marginBottom: 6 },
  sub: { color: "#666", marginTop: 6, marginBottom: 18 },
  grid: { flexDirection: "row", flexWrap: "wrap", justifyContent: "center" },
  drop: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", backgroundColor: "#fff", borderWidth: 2, borderColor: ORANGE, borderRadius: 14, paddingVertical: 16, paddingHorizontal: 18 },
  dropTxt: { fontSize: 17, fontWeight: "700", color: DARK }, dropArrow: { color: ORANGE, fontSize: 16 },
  list: { marginTop: 8, borderWidth: 1, borderColor: "#eadfd5", borderRadius: 14, backgroundColor: "#fffaf6", overflow: "hidden" },
  row: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingVertical: 15, paddingHorizontal: 18, borderBottomWidth: 1, borderColor: "#f0e6dc" },
  rowTxt: { fontSize: 16, fontWeight: "600", color: DARK },
  sizeBtn: { width: "44%", margin: "2.5%", paddingVertical: 20, borderRadius: 14, borderWidth: 2, borderColor: ORANGE, alignItems: "center" },
  sizeTxt: { fontSize: 17, fontWeight: "700", color: DARK },
  top: { flexDirection: "row", alignItems: "center", padding: 10, borderBottomWidth: 1, borderColor: "#eee" },
  chip: { flex: 1 }, chipTxt: { fontWeight: "800", fontSize: 16, color: DARK },
  wa: { backgroundColor: "#25a244", paddingHorizontal: 12, paddingVertical: 8, borderRadius: 10, marginRight: 8 },
  cartBtn: { backgroundColor: ORANGE, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 10 },
  secT: { fontSize: 18, fontWeight: "800", color: DARK }, secS: { color: ORANGE, marginBottom: 8, fontWeight: "600" },
  pgrid: { flexDirection: "row", flexWrap: "wrap", justifyContent: "space-between" },
  card: { width: "48.5%", marginBottom: 12 },
  cardImg: { width: "100%", aspectRatio: 1, borderRadius: 12, backgroundColor: "#f2f2f2" },
  cardName: { marginTop: 6, fontWeight: "600", color: DARK }, cardPrice: { color: ORANGE, fontWeight: "800", marginTop: 2 },
  modal: { position: "absolute", top: 0, bottom: 0, left: 0, right: 0, backgroundColor: "rgba(0,0,0,.5)", justifyContent: "flex-end" },
  sheet: { backgroundColor: "#fff", padding: 16, borderTopLeftRadius: 20, borderTopRightRadius: 20 },
  opt: { borderWidth: 1.5, borderColor: ORANGE, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 8, margin: 4 },
  optOn: { backgroundColor: ORANGE },
  btn: { backgroundColor: ORANGE, borderRadius: 12, paddingVertical: 14, paddingHorizontal: 24, alignItems: "center", marginTop: 14 },
  btnTxt: { color: "#fff", fontWeight: "800", fontSize: 16 },
  line: { flexDirection: "row", alignItems: "center", paddingVertical: 8, borderBottomWidth: 1, borderColor: "#eee" },
  q: { width: 30, height: 30, borderRadius: 15, backgroundColor: "#eee", alignItems: "center", justifyContent: "center" },
  tot: { backgroundColor: "#faf6f2", padding: 12, borderRadius: 12, marginTop: 14 },
  input: { borderWidth: 1, borderColor: "#ddd", borderRadius: 10, padding: 11, fontSize: 16 },
});
