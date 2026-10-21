package uz.ibtaxi.driver.ui

import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.ui.draw.scale
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Surface
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.border
import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.LocationManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import androidx.compose.material3.FilterChip
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Navigation
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Order
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.data.ShiftSummaryResponse
import uz.ibtaxi.driver.service.DriverService
import uz.ibtaxi.driver.taximeter.Fare
import androidx.compose.runtime.DisposableEffect
import androidx.compose.material3.CardDefaults
import uz.ibtaxi.driver.data.NavStep
import uz.ibtaxi.driver.data.Tariff
import uz.ibtaxi.driver.navigation.Instructions
import uz.ibtaxi.driver.navigation.Navigator
import uz.ibtaxi.driver.navigation.VoiceGuide
import java.time.Duration
import java.time.LocalTime
import java.time.OffsetDateTime

/**
 * Asosiy ekran: xarita, liniya holati, balans va joriy buyurtma (reja §8.4).
 */
@Composable
fun HomeScreen(onOpen: (Screen) -> Unit, requestPermissions: (() -> Unit) -> Unit) {
    val context = LocalContext.current
    val repository = IbTaxiApp.instance.repository
    val profile by DriverState.profile.collectAsState()
    val order by DriverState.order.collectAsState()
    val location by DriverState.location.collectAsState()
    val motion by DriverState.motion.collectAsState()
    val unread by DriverState.unread.collectAsState()
    var route by remember { mutableStateOf<List<List<Double>>?>(null) }
    var navigator by remember { mutableStateOf<Navigator?>(null) }
    var banner by remember { mutableStateOf<Pair<NavStep, Int?>?>(null) }
    var reroute by remember { mutableStateOf(0) }
    val session = IbTaxiApp.instance.session
    val voice = remember { VoiceGuide(context, session.locale) }
    DisposableEffect(Unit) { onDispose { voice.shutdown() } }
    var info by remember { mutableStateOf<String?>(null) }
    val realtimeConnected by DriverState.realtimeConnected.collectAsState()
    var problem by remember { mutableStateOf<Int?>(null) }
    var shift by remember { mutableStateOf<ShiftSummaryResponse?>(null) }
    // Safarda o'tilgan oraliq to'xtashlar — marshrut keyingi nuqtaga
    var passedStops by remember(order?.id) { mutableIntStateOf(order?.let { session.passedStops(it.id) } ?: 0) }
    val p = profile ?: return

    // Smena va navbat joyidagi o'rin: liniyada har 30 soniyada, buyurtma tugashi bilan darhol
    LaunchedEffect(p.status, order == null) {
        if (p.status == "offline") {
            shift = null
            return@LaunchedEffect
        }
        while (true) {
            (repository.shiftSummary() as? Result.Ok)?.value?.let { shift = it }
            delay(30_000)
        }
    }

    // Liniyadagi haydovchi buyurtma olmay qolishi sabablari: internet, GPS, Reverb ulanishi
    LaunchedEffect(p.status, realtimeConnected) {
        while (true) {
            problem = if (p.status == "offline") null else connectionProblem(context, realtimeConnected)
            delay(3000)
        }
    }

    // Marshrut: mijozgacha yoki manzilgacha (OSRM orqali)
    LaunchedEffect(order?.id, order?.status, location == null, reroute, passedStops) {
        val o = order
        val me = location
        route = null
        navigator = null
        banner = null
        if (o == null || me == null || o.status == "arrived") return@LaunchedEffect
        val (lat, lng) = navigationTarget(o, passedStops) ?: return@LaunchedEffect
        (repository.route(me.first, me.second, lat, lng) as? Result.Ok)?.value?.let {
            route = it.geometry
            val geometry = it.geometry
            if (it.steps.size > 1 && !geometry.isNullOrEmpty()) navigator = Navigator(it.steps, geometry)
        }
    }

    // Qadam-baqadam ko'rsatmalar: har GPS nuqtasida keyingi manevr, kerak bo'lsa ovoz
    LaunchedEffect(location, navigator) {
        val nav = navigator ?: return@LaunchedEffect
        val me = location ?: return@LaunchedEffect
        val (event, meters) = nav.update(me.first, me.second)
        banner = nav.upcoming?.let { it to meters }
        when (event) {
            is Navigator.Event.OffRoute -> reroute++
            is Navigator.Event.Prepare -> if (session.voiceGuidance) voice.say(Instructions.text(event.step, session.locale, event.meters))
            is Navigator.Event.Now -> if (session.voiceGuidance) voice.say(Instructions.text(event.step, session.locale))
            null -> Unit
        }
    }

    val appColors = LocalAppColors.current
    Box(Modifier.fillMaxSize()) {
        TaxiMap(
            styleUrl = mapStyleFor(p.config.mapStyle, appColors.dark),
            me = location?.let { MapPoint(it.first, it.second) },
            pickup = order?.pickup?.let { pl -> pl.lat?.let { MapPoint(it, pl.lng ?: 0.0) } },
            dropoff = order?.dropoff?.let { pl -> pl.lat?.let { MapPoint(it, pl.lng ?: 0.0) } },
            route = route,
            modifier = Modifier.fillMaxSize(),
            stops = order?.stops.orEmpty().drop(passedStops).map { MapPoint(it.lat, it.lng) },
            navigating = order?.status in setOf("assigned", "arrived", "in_progress"),
            bearing = motion.bearing,
            speedKmh = motion.speedKmh,
        )

        // Yuqori panel (maket A/B): pozivnoy, davlat raqami, balans, chat
        Column(Modifier.align(Alignment.TopCenter).fillMaxWidth().statusBarsPadding().padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            TopBar(p, unread, onOpen)

            problem?.let { text ->
                Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.errorContainer) {
                    Text(
                        stringResource(text),
                        Modifier.padding(horizontal = 16.dp, vertical = 10.dp),
                        color = MaterialTheme.colorScheme.onErrorContainer,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }

            // Navigatsiya banneri: ikkala rejimda qora, strelka sariq (maket A-Trip)
            banner?.let { (step, meters) ->
                Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(22.dp), color = NavBannerColor, shadowElevation = 8.dp) {
                    Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(arrow(step), style = MaterialTheme.typography.headlineLarge, fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.primary)
                        Column(Modifier.padding(start = 14.dp)) {
                            meters?.let { Text(formatDistance(it), style = MaterialTheme.typography.headlineSmall, color = Color.White) }
                            Text(Instructions.text(step, session.locale), color = Color(0xFFCFCFCF), maxLines = 2)
                        }
                    }
                }
            }

            // Navbat joyidagi o'rin — qora "chip" (maket A-Home)
            if (order == null) shift?.stand?.let { stand ->
                Surface(shape = RoundedCornerShape(999.dp), color = NavBannerColor) {
                    Text(
                        "${stringResource(R.string.queue)}: ${stand.stand} — ${stand.position} / ${stand.total}",
                        Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
        }

        // Pastki panel: buyurtma bo'lsa — uning bosqichlari, aks holda liniya holati va smena
        BottomSheet(Modifier.align(Alignment.BottomCenter)) {
            val current = order
            if (current != null) {
                OrderCard(current, passedStops, onChat = { onOpen(Screen.Chat) }) { count ->
                    passedStops = count
                    session.setPassedStops(current.id, count)
                }
            } else {
                val online = p.status != "offline"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier.size(12.dp).background(
                            if (online) appColors.success else MaterialTheme.colorScheme.outline,
                            CircleShape,
                        ),
                    )
                    Column(Modifier.weight(1f).padding(start = 12.dp)) {
                        Text(stringResource(if (online) R.string.online else R.string.offline), style = MaterialTheme.typography.titleLarge)
                        if (online) Text(stringResource(R.string.waiting_for_orders), style = MaterialTheme.typography.bodyMedium, color = appColors.muted)
                    }
                    Switch(
                        checked = online,
                        colors = SwitchDefaults.colors(
                            checkedTrackColor = MaterialTheme.colorScheme.primary,
                            checkedThumbColor = Color(0xFF111111),
                            checkedBorderColor = MaterialTheme.colorScheme.primary,
                        ),
                        modifier = Modifier.scale(1.25f).padding(end = 6.dp),
                        onCheckedChange = { goOnline ->
                            val toggle: () -> Unit = {
                                // Servis tarmoq so'rovidan oldin, ilova hali oldinda turganda: javobni kutib turganda
                                // haydovchi ilovadan chiqsa, Android 12+ fonda foreground servisga ruxsat bermaydi
                                if (goOnline) DriverService.start(context)
                                // Ilova scope'ida: ekran yopilsa ham servis va server holati ajralib qolmasin
                                IbTaxiApp.instance.scope.launch {
                                    when (val result = repository.setOnline(goOnline)) {
                                        is Result.Ok -> if (!goOnline) DriverService.stop(context)
                                        is Result.Error -> {
                                            if (goOnline) DriverService.stop(context)
                                            info = result.message ?: context.getString(R.string.network_error)
                                        }
                                    }
                                }
                            }
                            if (goOnline) requestPermissions(toggle) else toggle()
                        },
                    )
                }
                if (online) shift?.shift?.let { s ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        StatTile("${s.minutes / 60}:${"%02d".format(s.minutes % 60)}", stringResource(R.string.shift), Modifier.weight(1f))
                        StatTile("${s.orders}", stringResource(R.string.orders_label), Modifier.weight(1f))
                        StatTile(shortMoney(s.earnings), stringResource(R.string.earnings), Modifier.weight(1f))
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SheetButton(stringResource(R.string.dispatcher), Modifier.weight(1f), strong = true) { onOpen(Screen.Call) }
                    SheetButton(stringResource(R.string.balance), Modifier.weight(1f)) { onOpen(Screen.Balance) }
                    SheetButton(stringResource(R.string.settings), Modifier.weight(1f)) { onOpen(Screen.Settings) }
                }
            }
        }
    }

    info?.let { message ->
        AlertDialog(onDismissRequest = { info = null }, confirmButton = { TextButton({ info = null }) { Text("OK") } }, text = { Text(message) })
    }

    val receipt by DriverState.receipt.collectAsState()
    receipt?.let { total ->
        AlertDialog(
            onDismissRequest = {},
            title = { Text(stringResource(R.string.to_pay)) },
            text = { Text("${formatMoney(total)} ${stringResource(R.string.som)}", style = MaterialTheme.typography.displaySmall, fontWeight = FontWeight.Bold) },
            confirmButton = { Button(onClick = { DriverState.receipt(null) }) { Text(stringResource(R.string.paid)) } },
        )
    }
}

/**
 * Buyurtma bosqichlari: Yetib keldim → Yo'lovchi o'tirdi → Yakunlash; safar davomida taksometr.
 */
@Composable
private fun OrderCard(order: Order, passedStops: Int, onChat: () -> Unit, onPassedStops: (Int) -> Unit) {
    val context = LocalContext.current
    val repository = IbTaxiApp.instance.repository
    val scope = rememberCoroutineScope()
    val meters by DriverState.meters.collectAsState()
    val profile by DriverState.profile.collectAsState()
    var busy by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }
    val pendingComplete by DriverState.pendingComplete.collectAsState()
    val sending = pendingComplete == order.id
    var waitingSeconds by remember { mutableLongStateOf(0L) }
    val tariff = profile?.tariffs?.firstOrNull { it.tariffClassId == order.tariffClass?.id }

    // Kutish taymeri: "Yetib keldim" dan beri (qaror #33)
    LaunchedEffect(order.status, order.arrivedAt) {
        val arrived = order.arrivedAt?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() } ?: return@LaunchedEffect
        while (order.status == "arrived") {
            waitingSeconds = Duration.between(arrived, OffsetDateTime.now()).seconds.coerceAtLeast(0)
            delay(1000)
        }
    }

    fun act(block: suspend () -> Result<Order?>) {
        busy = true
        IbTaxiApp.instance.scope.launch {
            val result = block()
            if (result is Result.Error) DriverState.toast(result.message ?: context.getString(R.string.network_error))
            busy = false
        }
    }

    val appColors = LocalAppColors.current
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("#${order.id} · ${order.tariffClass?.name ?: ""}", style = MaterialTheme.typography.labelLarge, color = appColors.muted)

        // Manzillar: sariq halqa — qayerdan, kulrang — to'xtashlar, qora — qayerga (maket A-Offer)
        Column(
            Modifier.fillMaxWidth().background(appColors.tile, RoundedCornerShape(18.dp)).padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            PlaceRow(PlaceDot.Pickup, order.pickup.text ?: "", bold = order.status != "in_progress")
            order.stops.orEmpty().forEachIndexed { index, stop ->
                val passed = index < passedStops
                val next = order.status == "in_progress" && index == passedStops
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) {
                        PlaceRow(
                            PlaceDot.Stop,
                            "${if (passed) "✓ " else ""}${stop.text}",
                            bold = next,
                            passed = passed,
                        )
                    }
                    if (next) TextButton(onClick = { onPassedStops(index + 1) }) { Text(stringResource(R.string.stop_passed)) }
                }
            }
            order.dropoff?.text?.let { PlaceRow(PlaceDot.Dropoff, it, bold = order.status == "in_progress") }
        }

        order.company?.let {
            Surface(shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.tertiaryContainer) {
                Text(
                    "${stringResource(R.string.corporate_client)}: $it\n${stringResource(R.string.corporate_no_cash)}",
                    Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                    color = MaterialTheme.colorScheme.onTertiaryContainer,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
        order.priceBonus?.takeIf { it > 0 }?.let {
            Text("${stringResource(R.string.bonus)}: +${formatMoney(it)} ${stringResource(R.string.som)}", color = appColors.success, fontWeight = FontWeight.Bold)
        }
        order.comment?.let { Text("${stringResource(R.string.comment)}: $it", style = MaterialTheme.typography.bodyMedium, color = appColors.muted) }

        if (order.status == "arrived") {
            WaitingPanel(order, tariff, waitingSeconds)
        }
        if (order.status == "in_progress" && tariff != null) {
            TaximeterPanel(order, tariff, meters, profile?.config?.timezone)
        }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            TileIconButton(Icons.Default.Phone, stringResource(R.string.call_client)) {
                order.client?.phone?.let { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$it"))) }
            }
            TileIconButton(Icons.Default.Chat, stringResource(R.string.chat), onClick = onChat)
            TileIconButton(Icons.Default.Navigation, stringResource(R.string.open_navigator)) {
                navigationTarget(order, passedStops)?.let { (lat, lng) ->
                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:$lat,$lng?q=$lat,$lng")))
                }
            }
            if (order.status in setOf("assigned", "arrived")) {
                TextButton(onClick = { cancelling = true }, modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.cancel_order), color = MaterialTheme.colorScheme.error)
                }
            }
        }

        val mainButton = Modifier.fillMaxWidth().height(60.dp)
        when (order.status) {
            "assigned" -> Button(onClick = { act { repository.arrive(order.id) } }, enabled = !busy, modifier = mainButton, shape = RoundedCornerShape(18.dp)) {
                Text(stringResource(R.string.arrived), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
            }
            "arrived" -> Button(onClick = { act { repository.start(order.id) } }, enabled = !busy, modifier = mainButton, shape = RoundedCornerShape(18.dp)) {
                Text(stringResource(R.string.passenger_in), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
            }
            "in_progress" -> Button(
                onClick = {
                    busy = true
                    IbTaxiApp.instance.scope.launch {
                        when (val result = repository.complete(order.id, meters.first, meters.second)) {
                            is Result.Ok -> if (order.company == null) DriverState.receipt(result.value?.priceFinal)
                            // Internet yo'q — natija saqlangan, servis o'zi qayta yuboradi
                            is Result.Error -> if (result.code != -1) {
                                DriverState.toast(result.message ?: context.getString(R.string.network_error))
                            }
                        }
                        busy = false
                    }
                },
                enabled = !busy && !sending,
                shape = RoundedCornerShape(18.dp),
                // "Yakunlash" — qora (yorug'da) / oq (qorong'ida), sariq taksometrdan ajralib turadi
                colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.secondary, contentColor = MaterialTheme.colorScheme.onSecondary),
                modifier = mainButton,
            ) { Text(stringResource(if (sending) R.string.sending else R.string.finish), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold) }
        }
    }

    if (cancelling) CancelDialog(onDismiss = { cancelling = false }) { reason ->
        cancelling = false
        act { repository.cancel(order.id, reason) }
    }
}

@Composable
private fun CancelDialog(onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    val reasons = listOf(
        R.string.reason_client_no_show,
        R.string.reason_car_broken,
        R.string.reason_wrong_address,
        R.string.reason_client_asked,
    ).map { stringResource(it) }
    var selected by remember { mutableStateOf<String?>(null) }
    var other by remember { mutableStateOf(false) }
    var text by remember { mutableStateOf("") }
    val reason = if (other) text.trim() else selected

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.cancel_reason)) },
        text = {
            // Sabablar ro'yxati qat'iy — hisobotda guruhlanadi; "Boshqa" uchun matn
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                reasons.forEach { option ->
                    FilterChip(
                        selected = !other && selected == option,
                        onClick = { selected = option; other = false },
                        label = { Text(option) },
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                FilterChip(
                    selected = other,
                    onClick = { other = true },
                    label = { Text(stringResource(R.string.reason_other)) },
                    modifier = Modifier.fillMaxWidth(),
                )
                if (other) OutlinedTextField(value = text, onValueChange = { text = it }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            Button(onClick = { reason?.let(onConfirm) }, enabled = !reason.isNullOrBlank()) { Text(stringResource(R.string.cancel_order)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("✕") } },
    )
}

/** Banner matni yoki null — hammasi joyida */
private fun connectionProblem(context: Context, realtimeConnected: Boolean): Int? {
    val network = context.getSystemService(ConnectivityManager::class.java)
    val online = network?.getNetworkCapabilities(network.activeNetwork)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
    val locationManager = context.getSystemService(LocationManager::class.java)
    val gpsOn = locationManager?.let { LocationManagerCompat.isLocationEnabled(it) } == true
    val permitted = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    return when {
        !online -> R.string.problem_no_internet
        !permitted || !gpsOn -> R.string.problem_gps_off
        !realtimeConnected -> R.string.problem_realtime
        else -> null
    }
}

private fun arrow(step: NavStep): String = when {
    step.type == "arrive" -> "🏁"
    step.type == "roundabout" || step.type == "rotary" -> "⟳"
    step.modifier == "uturn" -> "↶"
    step.modifier?.contains("left") == true -> if (step.modifier.startsWith("slight")) "↖" else "←"
    step.modifier?.contains("right") == true -> if (step.modifier.startsWith("slight")) "↗" else "→"
    else -> "↑"
}

private fun formatDistance(meters: Int): String =
    if (meters >= 1000) String.format(java.util.Locale.US, "%.1f km", meters / 1000.0) else "${(meters / 10) * 10} m"

/**
 * Taksometr (safar davomida): katta narx, masofa, safar vaqti, kutish; server yakunda xuddi shu formula bilan
 * hisoblaydi (kutish = "Yetib keldim" → "Boshladim", tungi narx — safar boshlangan vaqt bo'yicha).
 */
/**
 * Mijozni kutish (qaror #33): taymer, bepul daqiqalar qoldig'i va hozirgi narx — haydovchi va mijoz ko'radi.
 * Tarifda kutish o'chiq bo'lsa — narx o'zgarmasligi aniq yoziladi.
 */
@Composable
private fun WaitingPanel(order: Order, tariff: Tariff?, waitingSeconds: Long) {
    val charged = tariff?.waitingEnabled == true && tariff.waitingPerMin > 0
    val freeLeft = tariff?.let { (it.waitingFreeMin * 60L - waitingSeconds).coerceAtLeast(0) } ?: 0L
    val fare = tariff?.let {
        Fare.calculate(it, 0, waitingSeconds = waitingSeconds, surgeCoeff = order.surgeCoeff ?: 1.0, discountPercent = order.discountPercent ?: 0)
    }
    val ink = MaterialTheme.colorScheme.onSurface
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = LocalAppColors.current.tile)) {
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            MeterValue("${waitingSeconds / 60}:${"%02d".format(waitingSeconds % 60)}", stringResource(R.string.waiting), ink)
            if (charged && freeLeft > 0) {
                MeterValue("${freeLeft / 60}:${"%02d".format(freeLeft % 60)}", stringResource(R.string.waiting_free_left), ink)
            }
            if (fare != null) {
                MeterValue("${formatMoney(fare.total + (order.priceBonus ?: 0))}", stringResource(R.string.som), ink)
            }
        }
        if (!charged) {
            Text(
                stringResource(R.string.waiting_not_charged),
                Modifier.padding(start = 12.dp, end = 12.dp, bottom = 10.dp),
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
}

@Composable
private fun TaximeterPanel(order: Order, tariff: Tariff, meters: Pair<Long, Long>, timezone: String?) {
    // Tungi tarif filial vaqti bo'yicha (server ham shunday hisoblaydi), telefon zonasi boshqa bo'lsa ham
    val zone = timezone?.let { runCatching { java.time.ZoneId.of(it) }.getOrNull() } ?: java.time.ZoneId.systemDefault()
    val started = order.startedAt?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() }
    val arrived = order.arrivedAt?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() }
    val waitingSeconds = if (started != null && arrived != null) Duration.between(arrived, started).seconds.coerceAtLeast(0) else 0L
    var rideSeconds by remember { mutableLongStateOf(0L) }
    LaunchedEffect(order.id, started) {
        while (started != null) {
            rideSeconds = Duration.between(started, OffsetDateTime.now()).seconds.coerceAtLeast(0)
            delay(1000)
        }
    }
    val fare = Fare.calculate(
        tariff,
        meters.first,
        meters.second,
        waitingSeconds = waitingSeconds,
        startedAt = started?.atZoneSameInstant(zone)?.toLocalTime() ?: LocalTime.now(zone),
        surgeCoeff = order.surgeCoeff ?: 1.0,
        discountPercent = order.discountPercent ?: 0,
    )
    // Operator ustamasi formulaga kirmaydi — yakuniy narxga qo'shiladi (server ham shunday)
    val total = fare.total + (order.priceBonus ?: 0)

    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.primary) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.taximeter), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.onPrimary)
                Text(
                    "${formatMoney(total)} ${stringResource(R.string.som)}",
                    style = MaterialTheme.typography.headlineMedium,
                    color = MaterialTheme.colorScheme.onPrimary,
                    maxLines = 1,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text("%.1f ${stringResource(R.string.km)}".format((meters.first + meters.second) / 1000.0), fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimary)
                Text("${rideSeconds / 60}:${"%02d".format(rideSeconds % 60)}", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimary)
                if (waitingSeconds > 0) Text("${stringResource(R.string.waiting)} ${waitingSeconds / 60}:${"%02d".format(waitingSeconds % 60)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onPrimary)
                if (meters.second > 0) Text("${stringResource(R.string.outside_km)}: %.1f".format(meters.second / 1000.0), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onPrimary)
            }
        }
    }
}

/** Safar maqsadi: yo'lda — keyingi o'tilmagan to'xtash yoki "qayerga", undan oldin — mijoz */
private fun navigationTarget(order: Order, passedStops: Int): Pair<Double, Double>? {
    if (order.status != "in_progress") return order.pickup.lat?.let { lat -> order.pickup.lng?.let { lat to it } }
    order.stops.orEmpty().getOrNull(passedStops)?.let { return it.lat to it.lng }
    return order.dropoff?.lat?.let { lat -> order.dropoff.lng?.let { lat to it } }
}

@Composable
private fun MeterValue(value: String, label: String, color: Color = MaterialTheme.colorScheme.onPrimary) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold, color = color)
        Text(label, style = MaterialTheme.typography.labelSmall, color = color.copy(alpha = 0.75f))
    }
}

/** Yuqori panel: pozivnoy plitkasi, davlat raqami, mashina va reyting, balans, chat */
@Composable
private fun TopBar(p: uz.ibtaxi.driver.data.DriverProfile, unread: Int, onOpen: (Screen) -> Unit) {
    val appColors = LocalAppColors.current
    Surface(Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surface, shadowElevation = 6.dp) {
        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(46.dp).background(MaterialTheme.colorScheme.primary, RoundedCornerShape(14.dp)),
                contentAlignment = Alignment.Center,
            ) {
                Text(p.callsign, fontWeight = FontWeight.ExtraBold, color = MaterialTheme.colorScheme.onPrimary, maxLines = 1)
            }
            Column(Modifier.weight(1f).padding(horizontal = 10.dp)) {
                Text(p.vehicle?.plateNumber ?: p.name, fontWeight = FontWeight.ExtraBold, style = MaterialTheme.typography.titleMedium, maxLines = 1)
                Text(
                    listOfNotNull(p.vehicle?.model, p.vehicle?.color, "★ ${p.rating}").joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                    color = appColors.muted,
                    maxLines = 1,
                )
            }
            Surface(
                onClick = { onOpen(Screen.Balance) },
                shape = RoundedCornerShape(14.dp),
                color = appColors.tile,
            ) {
                Text(
                    formatMoney(p.balance),
                    Modifier.padding(horizontal = 10.dp, vertical = 9.dp),
                    fontWeight = FontWeight.ExtraBold,
                    color = if (p.balance < p.minBalance) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
                )
            }
            Box(Modifier.padding(start = 6.dp)) {
                TileIconButton(Icons.Default.Chat, stringResource(R.string.chat), size = 44) { onOpen(Screen.Chat) }
                if (unread > 0) Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(9.dp).background(Color(0xFFE5484D), CircleShape))
            }
        }
    }
}

/** Pastki panel: yuqori burchaklari yumaloq, tortish belgisi bilan (maket A) */
@Composable
private fun BottomSheet(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Surface(
        modifier.fillMaxWidth(),
        shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
        color = MaterialTheme.colorScheme.surface,
        shadowElevation = 12.dp,
    ) {
        Column(Modifier.navigationBarsPadding().padding(start = 16.dp, end = 16.dp, top = 10.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.align(Alignment.CenterHorizontally).size(width = 40.dp, height = 5.dp).background(MaterialTheme.colorScheme.outline, RoundedCornerShape(3.dp)))
            content()
        }
    }
}

@Composable
private fun StatTile(value: String, label: String, modifier: Modifier = Modifier) {
    val appColors = LocalAppColors.current
    Column(modifier.background(appColors.tile, RoundedCornerShape(16.dp)).padding(12.dp)) {
        Text(value, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        Text(label, style = MaterialTheme.typography.bodySmall, color = appColors.muted)
    }
}

@Composable
private fun SheetButton(text: String, modifier: Modifier = Modifier, strong: Boolean = false, onClick: () -> Unit) {
    val appColors = LocalAppColors.current
    Button(
        onClick = onClick,
        modifier = modifier.height(52.dp),
        shape = RoundedCornerShape(16.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 6.dp),
        colors = if (strong) {
            ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.secondary, contentColor = MaterialTheme.colorScheme.onSecondary)
        } else {
            ButtonDefaults.buttonColors(containerColor = appColors.tile, contentColor = MaterialTheme.colorScheme.onSurface)
        },
    ) { Text(text, fontWeight = FontWeight.Bold, maxLines = 1) }
}

@Composable
private fun TileIconButton(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, size: Int = 56, onClick: () -> Unit) {
    Surface(onClick = onClick, shape = RoundedCornerShape(16.dp), color = LocalAppColors.current.tile, modifier = Modifier.size(size.dp)) {
        Box(contentAlignment = Alignment.Center) { Icon(icon, contentDescription = label) }
    }
}

enum class PlaceDot { Pickup, Stop, Dropoff }

/** Manzil qatori: nuqta belgisi + matn (taklif va buyurtma kartasida bir xil) */
@Composable
fun PlaceRow(dot: PlaceDot, text: String, bold: Boolean = true, passed: Boolean = false) {
    val ink = MaterialTheme.colorScheme.onSurface
    Row(verticalAlignment = Alignment.CenterVertically) {
        when (dot) {
            PlaceDot.Pickup -> Box(Modifier.size(14.dp).background(MaterialTheme.colorScheme.primary, CircleShape).border(3.dp, ink, CircleShape))
            PlaceDot.Stop -> Box(Modifier.padding(horizontal = 2.dp).size(10.dp).background(LocalAppColors.current.muted, RoundedCornerShape(3.dp)))
            PlaceDot.Dropoff -> Box(Modifier.size(14.dp).background(ink, CircleShape))
        }
        Text(
            text,
            Modifier.padding(start = 10.dp),
            style = if (bold) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium,
            fontWeight = if (bold) FontWeight.Bold else null,
            textDecoration = if (passed) TextDecoration.LineThrough else null,
            color = if (passed) MaterialTheme.colorScheme.outline else ink,
        )
    }
}

/** 145 000 → 145k (kichik plitkaga sig'sin) */
private fun shortMoney(value: Long): String = if (value >= 100_000) "${value / 1000}k" else formatMoney(value)
