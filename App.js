import React, { useEffect, useMemo, useState } from "react";
import {
  View, Text, Image, ScrollView, TouchableOpacity, TextInput, ActivityIndicator,
  Linking, Alert, SafeAreaView, StyleSheet, Platform, StatusBar as RNStatusBar,
} from "react-native";
import { StatusBar } from "expo-status-bar";
import { Feather } from "@expo/vector-icons";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { WHATSAPP } from "./src/config";
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
        <Shop size={size} secs={secs} loading={!products && !err} err={err} retry={load} total={cartSubtotal(cart) - cartDiscount(cart)}
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


const INK = "#141A17", PAPER = "#FFFFFF", MIST = "#F1F2EF", LINE = "#E3E5E0", MUTED = "#7A817C", COPPER = "#C4551A";
const SERIF = Platform.OS === "ios" ? "Didot" : "serif";

function Btn({ label, onPress, disabled, busy, light }) {
  return (
    <TouchableOpacity activeOpacity={0.85} disabled={disabled || busy} onPress={onPress}
      style={[s.btn, light && s.btnLight, (disabled || busy) && { opacity: 0.35 }]}>
      {busy ? <ActivityIndicator color={light ? INK : "#fff"} /> : <Text style={[s.btnTxt, light && { color: INK }]}>{label}</Text>}
    </TouchableOpacity>
  );
}

function Home({ onPick }) {
  const [open, setOpen] = useState(false);
  return (
    <ScrollView contentContainerStyle={{ flexGrow: 1, paddingHorizontal: 28, paddingTop: 56, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <View style={{ alignItems: "center" }}>
        <Image source={logo} style={{ width: 230, height: 110, resizeMode: "contain" }} />
        <View style={{ width: 36, height: 1, backgroundColor: COPPER, marginVertical: 30 }} />
        <Text style={s.display}>Pour quelle montre ?</Text>
        <Text style={s.lede}>Choisissez votre modèle, nous vous montrons uniquement les bracelets, coques et protections qui lui vont.</Text>
      </View>
      <View style={{ marginTop: 34 }}>
        <TouchableOpacity style={s.drop} onPress={() => setOpen(!open)} activeOpacity={0.8}>
          <Text style={s.dropTxt}>Taille de la montre</Text>
          <Feather name={open ? "chevron-up" : "chevron-down"} size={20} color={INK} />
        </TouchableOpacity>
        {open && (
          <View style={s.list}>
            {WATCH_SIZES.map((x, i) => (
              <TouchableOpacity key={x.key} style={[s.row, i === WATCH_SIZES.length - 1 && { borderBottomWidth: 0 }]} onPress={() => onPick(x)}>
                <Text style={s.rowTxt}>{x.label}</Text>
                <Feather name="arrow-right" size={16} color={MUTED} />
              </TouchableOpacity>
            ))}
          </View>
        )}
      </View>
    </ScrollView>
  );
}

function Shop({ size, secs, loading, err, retry, onSize, onOpen, n, total, onCart }) {
  return (
    <View style={{ flex: 1, backgroundColor: PAPER }}>
      <View style={s.header}>
        <TouchableOpacity onPress={() => Linking.openURL("https://wa.me/" + WHATSAPP)} style={s.hIcon} hitSlop={10}>
          <Feather name="message-circle" size={22} color={INK} />
        </TouchableOpacity>
        <Image source={logo} style={{ width: 120, height: 44, resizeMode: "contain" }} />
        <TouchableOpacity onPress={onCart} style={s.hIcon} hitSlop={10}>
          <Feather name="shopping-bag" size={22} color={INK} />
          {n > 0 && <View style={s.badge}><Text style={s.badgeTxt}>{n}</Text></View>}
        </TouchableOpacity>
      </View>
      <TouchableOpacity onPress={onSize} style={s.sizeBar} activeOpacity={0.7}>
        <Text style={s.sizeBarTxt}>Apple Watch {size ? size.label : ""}</Text>
        <Text style={s.sizeBarLink}>Modifier</Text>
      </TouchableOpacity>
      {loading && <View style={s.center}><ActivityIndicator size="small" color={INK} /></View>}
      {!!err && (
        <View style={s.center}>
          <Text style={{ textAlign: "center", marginBottom: 6, color: INK, fontSize: 16 }}>Connexion impossible</Text>
          <Text style={{ textAlign: "center", marginBottom: 18, color: MUTED }}>{err}</Text>
          <Btn label="Réessayer" onPress={retry} />
        </View>
      )}
      {!loading && !err && (
        <ScrollView contentContainerStyle={{ paddingBottom: n > 0 ? 120 : 40 }} showsVerticalScrollIndicator={false}>
          {!secs.length && <Text style={{ textAlign: "center", marginTop: 60, color: MUTED }}>Rien de disponible pour cette taille pour le moment.</Text>}
          {secs.map((sec) => (
            <View key={sec.key} style={{ marginTop: 34 }}>
              <View style={{ paddingHorizontal: 20, marginBottom: 16 }}>
                <Text style={s.secT}>{sec.title}</Text>
                {!!sec.sub && <Text style={s.secS}>{sec.sub}</Text>}
              </View>
              <View style={s.pgrid}>
                {sec.items.map((it) => (
                  <TouchableOpacity key={it.p.id} style={s.card} onPress={() => onOpen(it)} activeOpacity={0.85}>
                    <View style={s.cardImgWrap}>
                      {it.p.images && it.p.images[0] ? <Image source={{ uri: it.p.images[0].thumbnail || it.p.images[0].src }} style={s.cardImg} resizeMode="contain" /> : null}
                    </View>
                    <Text numberOfLines={2} style={s.cardName}>{it.p.name}</Text>
                    <Text style={s.cardPrice}>{fmt(price(it.p))}</Text>
                  </TouchableOpacity>
                ))}
              </View>
            </View>
          ))}
        </ScrollView>
      )}
      {n > 0 && !loading && (
        <View style={s.cartBarWrap} pointerEvents="box-none">
          <TouchableOpacity style={s.cartBar} onPress={onCart} activeOpacity={0.9}>
            <Text style={s.cartBarL}>Voir le panier · {n} article{n > 1 ? "s" : ""}</Text>
            <Text style={s.cartBarR}>{fmt(total)}</Text>
          </TouchableOpacity>
        </View>
      )}
    </View>
  );
}

function Detail({ it, onClose, onAdd }) {
  const { p, opts } = it;
  const [sel, setSel] = useState(opts.length === 1 ? opts[0] : null);
  const img = p.images && p.images[0] && p.images[0].src;
  const need = opts.length > 1 && !sel;
  return (
    <View style={s.modal}>
      <TouchableOpacity style={{ flex: 1 }} activeOpacity={1} onPress={onClose} />
      <View style={s.sheet}>
        <TouchableOpacity onPress={onClose} style={s.close} hitSlop={12}><Feather name="x" size={22} color={INK} /></TouchableOpacity>
        <View style={s.detailImg}>{!!img && <Image source={{ uri: img }} style={{ width: "100%", height: "100%" }} resizeMode="contain" />}</View>
        <Text style={s.detailName}>{p.name}</Text>
        <Text style={s.detailPrice}>{fmt(price(p))}</Text>
        {opts.length > 1 && (
          <View style={{ marginTop: 18 }}>
            <Text style={s.label}>Votre modèle de montre</Text>
            <View style={{ flexDirection: "row", flexWrap: "wrap", marginTop: 8, marginHorizontal: -4 }}>
              {opts.map((o) => {
                const on = sel && sel.value === o.value;
                return (
                  <TouchableOpacity key={o.value} onPress={() => setSel(o)} style={[s.opt, on && s.optOn]}>
                    <Text style={{ color: on ? "#fff" : INK, fontSize: 14 }}>{o.label}</Text>
                  </TouchableOpacity>
                );
              })}
            </View>
          </View>
        )}
        <View style={{ marginTop: 22 }}>
          <Btn label="Ajouter au panier" disabled={need} onPress={() => onAdd(p, sel || opts[0] || null)} />
        </View>
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
    if (!f.name.trim() || !f.phone.trim() || !f.address.trim()) { Alert.alert("Informations manquantes", "Indiquez votre nom, votre téléphone et votre adresse de livraison."); return; }
    setBusy(true);
    try { onDone(await placeOrder(cart, f)); }
    catch (e) { Alert.alert("Commande impossible", (e.message || "Une erreur est survenue.") + "\n\nVous pouvez aussi nous écrire sur WhatsApp."); }
    setBusy(false);
  }
  return (
    <View style={{ flex: 1, backgroundColor: PAPER }}>
      <View style={s.header}>
        <TouchableOpacity onPress={back} style={s.hIcon} hitSlop={10}><Feather name="arrow-left" size={22} color={INK} /></TouchableOpacity>
        <Text style={{ fontFamily: SERIF, fontSize: 20, color: INK }}>Panier</Text>
        <View style={s.hIcon} />
      </View>
      <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: 70 }} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        {!cart.length && <Text style={{ color: MUTED, marginVertical: 40, textAlign: "center" }}>Votre panier est vide.</Text>}
        {cart.map((l) => (
          <View key={l.key} style={s.line}>
            <View style={s.lineImg}>{l.img ? <Image source={{ uri: l.img }} style={{ width: "100%", height: "100%" }} resizeMode="contain" /> : null}</View>
            <View style={{ flex: 1, marginHorizontal: 14 }}>
              <Text numberOfLines={2} style={{ color: INK, fontSize: 15 }}>{l.name}</Text>
              {!!l.label && <Text style={{ color: MUTED, fontSize: 13, marginTop: 2 }}>{l.label}</Text>}
              <Text style={{ color: INK, fontFamily: SERIF, fontSize: 16, marginTop: 6 }}>{fmt(l.price)}</Text>
            </View>
            <View style={s.stepper}>
              <TouchableOpacity onPress={() => qty(l.key, -1)} hitSlop={8}><Feather name="minus" size={16} color={INK} /></TouchableOpacity>
              <Text style={{ minWidth: 26, textAlign: "center", color: INK }}>{l.qty}</Text>
              <TouchableOpacity onPress={() => qty(l.key, 1)} hitSlop={8}><Feather name="plus" size={16} color={INK} /></TouchableOpacity>
            </View>
          </View>
        ))}
        {!!cart.length && (
          <>
            <View style={{ marginTop: 22 }}>
              <Row a="Sous-total" b={fmt(sub)} />
              {disc > 0 && <Row a="Remise sur le lot" b={"− " + fmt(disc)} accent />}
              <View style={{ height: 1, backgroundColor: LINE, marginVertical: 12 }} />
              <Row a="Total" b={fmt(sub - disc)} big />
              <Text style={{ color: MUTED, marginTop: 8, fontSize: 13 }}>Livraison calculée à la commande. Paiement à la livraison.</Text>
            </View>
            <Text style={[s.secT, { marginTop: 36, marginBottom: 4 }]}>Livraison</Text>
            <Field l="Nom complet" v={f.name} on={set("name")} />
            <Field l="Téléphone" v={f.phone} on={set("phone")} kb="phone-pad" />
            <Field l="Adresse ou quartier" v={f.address} on={set("address")} />
            <Field l="Ville" v={f.city} on={set("city")} />
            <Field l="Email (facultatif)" v={f.email} on={set("email")} kb="email-address" />
            <Field l="Note (facultatif)" v={f.note} on={set("note")} />
            <View style={{ marginTop: 28 }}><Btn label="Commander" busy={busy} onPress={go} /></View>
          </>
        )}
      </ScrollView>
    </View>
  );
}
const Row = ({ a, b, big, accent }) => (
  <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "baseline", marginVertical: 3 }}>
    <Text style={{ color: accent ? COPPER : INK, fontSize: big ? 17 : 15 }}>{a}</Text>
    <Text style={{ color: accent ? COPPER : INK, fontFamily: big ? SERIF : undefined, fontSize: big ? 22 : 15 }}>{b}</Text>
  </View>
);
const Field = ({ l, v, on, kb }) => (
  <View style={{ marginTop: 16 }}>
    <Text style={s.label}>{l}</Text>
    <TextInput value={v} onChangeText={on} keyboardType={kb} autoCapitalize={kb ? "none" : "sentences"} style={s.input} />
  </View>
);

function Done({ r, onHome }) {
  return (
    <View style={[s.center, { backgroundColor: PAPER }]}>
      <Image source={logo} style={{ width: 160, height: 76, resizeMode: "contain" }} />
      <View style={{ width: 36, height: 1, backgroundColor: COPPER, marginVertical: 26 }} />
      <Text style={s.display}>Merci !</Text>
      <Text style={[s.lede, { marginBottom: 6 }]}>Commande n° {r && (r.order_number || r.order_id)}</Text>
      <Text style={[s.lede, { marginBottom: 32 }]}>Nous vous appelons très vite pour organiser la livraison.</Text>
      <Btn label="Retour à la boutique" onPress={onHome} light />
    </View>
  );
}

const s = StyleSheet.create({
  root: { flex: 1, backgroundColor: PAPER, paddingTop: Platform.OS === "android" ? RNStatusBar.currentHeight : 0 },
  center: { flex: 1, alignItems: "center", justifyContent: "center", padding: 28 },
  display: { fontFamily: SERIF, fontSize: 32, color: INK, textAlign: "center", letterSpacing: 0.3 },
  lede: { color: MUTED, fontSize: 15, lineHeight: 22, textAlign: "center", marginTop: 12, maxWidth: 320 },
  label: { color: MUTED, fontSize: 13, letterSpacing: 0.3 },
  drop: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", borderWidth: 1, borderColor: INK, paddingVertical: 18, paddingHorizontal: 18 },
  dropTxt: { fontSize: 16, color: INK },
  list: { borderWidth: 1, borderTopWidth: 0, borderColor: INK },
  row: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingVertical: 16, paddingHorizontal: 18, borderBottomWidth: 1, borderColor: LINE },
  rowTxt: { fontSize: 16, color: INK },
  header: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: 18, paddingVertical: 6 },
  hIcon: { width: 40, height: 40, alignItems: "center", justifyContent: "center" },
  badge: { position: "absolute", top: 3, right: 1, minWidth: 17, height: 17, borderRadius: 9, backgroundColor: COPPER, alignItems: "center", justifyContent: "center", paddingHorizontal: 4 },
  badgeTxt: { color: "#fff", fontSize: 10, fontWeight: "700" },
  sizeBar: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingHorizontal: 20, paddingVertical: 12, backgroundColor: MIST },
  sizeBarTxt: { color: INK, fontSize: 14 },
  sizeBarLink: { color: INK, fontSize: 13, textDecorationLine: "underline" },
  secT: { fontFamily: SERIF, fontSize: 26, color: INK, letterSpacing: 0.2 },
  secS: { color: COPPER, fontSize: 13, marginTop: 4 },
  pgrid: { flexDirection: "row", flexWrap: "wrap", paddingHorizontal: 12 },
  card: { width: "50%", paddingHorizontal: 8, marginBottom: 22 },
  cardImgWrap: { width: "100%", aspectRatio: 0.9, backgroundColor: MIST, alignItems: "center", justifyContent: "center", overflow: "hidden" },
  cardImg: { width: "88%", height: "88%" },
  cardName: { marginTop: 10, color: INK, fontSize: 14, lineHeight: 19 },
  cardPrice: { marginTop: 3, color: INK, fontFamily: SERIF, fontSize: 16 },
  cartBarWrap: { position: "absolute", left: 0, right: 0, bottom: 0, padding: 16, paddingBottom: 26 },
  cartBar: { backgroundColor: INK, flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingVertical: 17, paddingHorizontal: 20 },
  cartBarL: { color: "#fff", fontSize: 14, letterSpacing: 0.3 },
  cartBarR: { color: "#fff", fontFamily: SERIF, fontSize: 17 },
  modal: { position: "absolute", top: 0, bottom: 0, left: 0, right: 0, backgroundColor: "rgba(20,26,23,.55)", justifyContent: "flex-end" },
  sheet: { backgroundColor: PAPER, padding: 20, paddingBottom: 34 },
  close: { position: "absolute", right: 16, top: 14, zIndex: 3, width: 34, height: 34, alignItems: "center", justifyContent: "center", backgroundColor: PAPER, borderRadius: 17 },
  detailImg: { width: "100%", height: 300, backgroundColor: MIST },
  detailName: { fontFamily: SERIF, fontSize: 24, color: INK, marginTop: 18, lineHeight: 30 },
  detailPrice: { fontSize: 17, color: COPPER, marginTop: 6 },
  opt: { borderWidth: 1, borderColor: LINE, paddingHorizontal: 16, paddingVertical: 11, margin: 4 },
  optOn: { backgroundColor: INK, borderColor: INK },
  btn: { backgroundColor: INK, paddingVertical: 17, alignItems: "center", justifyContent: "center", borderWidth: 1, borderColor: INK },
  btnLight: { backgroundColor: PAPER },
  btnTxt: { color: "#fff", fontSize: 14, letterSpacing: 1.2 },
  line: { flexDirection: "row", alignItems: "center", paddingVertical: 14, borderBottomWidth: 1, borderColor: LINE },
  lineImg: { width: 68, height: 76, backgroundColor: MIST },
  stepper: { flexDirection: "row", alignItems: "center", borderWidth: 1, borderColor: LINE, paddingHorizontal: 8, paddingVertical: 6 },
  input: { borderBottomWidth: 1, borderColor: INK, paddingVertical: 10, fontSize: 16, color: INK },
});
