package uz.ibtaxi.driver.ui

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
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Order
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.service.DriverService
import uz.ibtaxi.driver.taximeter.Fare
import androidx.compose.runtime.DisposableEffect
import androidx.compose.material3.CardDefaults
import uz.ibtaxi.driver.data.NavStep
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
    val scope = rememberCoroutineScope()
    val profile by DriverState.profile.collectAsState()
    val order by DriverState.order.collectAsState()
    val location by DriverState.location.collectAsState()
    val unread by DriverState.unread.collectAsState()
    var route by remember { mutableStateOf<List<List<Double>>?>(null) }
    var navigator by remember { mutableStateOf<Navigator?>(null) }
    var banner by remember { mutableStateOf<Pair<NavStep, Int?>?>(null) }
    var reroute by remember { mutableStateOf(0) }
    val session = IbTaxiApp.instance.session
    val voice = remember { VoiceGuide(context, session.locale) }
    DisposableEffect(Unit) { onDispose { voice.shutdown() } }
    var info by remember { mutableStateOf<String?>(null) }
    val p = profile ?: return

    // Marshrut: mijozgacha yoki manzilgacha (OSRM orqali)
    LaunchedEffect(order?.id, order?.status, location == null, reroute) {
        val o = order
        val me = location
        route = null
        navigator = null
        banner = null
        if (o == null || me == null || o.status == "arrived") return@LaunchedEffect
        val target = if (o.status == "in_progress") o.dropoff else o.pickup
        val lat = target?.lat ?: return@LaunchedEffect
        val lng = target.lng ?: return@LaunchedEffect
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

    Box(Modifier.fillMaxSize()) {
        TaxiMap(
            styleUrl = p.config.mapStyle,
            me = location?.let { MapPoint(it.first, it.second) },
            pickup = order?.pickup?.let { pl -> pl.lat?.let { MapPoint(it, pl.lng ?: 0.0) } },
            dropoff = order?.dropoff?.let { pl -> pl.lat?.let { MapPoint(it, pl.lng ?: 0.0) } },
            route = route,
            modifier = Modifier.fillMaxSize(),
        )

        // Yuqori panel: holat, balans, chat, sozlamalar
        Row(
            Modifier.fillMaxWidth().padding(12.dp).background(MaterialTheme.colorScheme.surface.copy(alpha = 0.95f), RoundedCornerShape(16.dp)).padding(horizontal = 12.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text("${p.callsign} · ${p.vehicle?.plateNumber ?: ""}", fontWeight = FontWeight.Bold)
                Text(
                    "${stringResource(R.string.balance)}: ${formatMoney(p.balance)} · ${stringResource(R.string.rating)}: ${p.rating}",
                    style = MaterialTheme.typography.bodySmall,
                    color = if (p.balance < p.minBalance) MaterialTheme.colorScheme.error else Color.Unspecified,
                )
            }
            IconButton(onClick = { onOpen(Screen.Call) }) { Icon(Icons.Default.Call, stringResource(R.string.call_dispatcher)) }
            IconButton(onClick = { onOpen(Screen.Balance) }) { Icon(Icons.Default.AccountBalanceWallet, null) }
            IconButton(onClick = { onOpen(Screen.Chat) }) {
                BadgedBox(badge = { if (unread > 0) Badge { Text("$unread") } }) { Icon(Icons.Default.Chat, null) }
            }
            IconButton(onClick = { onOpen(Screen.Settings) }) { Icon(Icons.Default.Settings, null) }
        }

        // Navigatsiya banneri: keyingi manevr va unga qadar masofa
        banner?.let { (step, meters) ->
            Card(
                Modifier.align(Alignment.TopCenter).padding(top = 76.dp, start = 12.dp, end = 12.dp).fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primary),
            ) {
                Row(Modifier.padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(arrow(step), style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.onPrimary)
                    Column(Modifier.padding(start = 12.dp)) {
                        meters?.let { Text(formatDistance(it), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimary) }
                        Text(Instructions.text(step, session.locale), color = MaterialTheme.colorScheme.onPrimary, maxLines = 2)
                    }
                }
            }
        }

        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(12.dp)) {
            val current = order
            if (current != null) {
                OrderCard(current)
            } else {
                val online = p.status != "offline"
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(if (online) R.string.online else R.string.offline), style = MaterialTheme.typography.titleMedium)
                            if (online) Text(stringResource(R.string.no_orders), style = MaterialTheme.typography.bodySmall)
                        }
                        Switch(checked = online, onCheckedChange = { goOnline ->
                            val toggle: () -> Unit = {
                                scope.launch {
                                    when (val result = repository.setOnline(goOnline)) {
                                        is Result.Ok -> if (goOnline) DriverService.start(context) else DriverService.stop(context)
                                        is Result.Error -> info = result.message ?: context.getString(R.string.network_error)
                                    }
                                }
                            }
                            if (goOnline) requestPermissions(toggle) else toggle()
                        })
                    }
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
private fun OrderCard(order: Order) {
    val context = LocalContext.current
    val repository = IbTaxiApp.instance.repository
    val scope = rememberCoroutineScope()
    val meters by DriverState.meters.collectAsState()
    val profile by DriverState.profile.collectAsState()
    var busy by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }
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

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("#${order.id} · ${order.tariffClass?.name ?: ""}", style = MaterialTheme.typography.labelLarge)
            Text("${stringResource(R.string.pickup)}: ${order.pickup.text ?: ""}", style = MaterialTheme.typography.titleMedium)
            order.dropoff?.text?.let { Text("${stringResource(R.string.dropoff)}: $it") }
            order.comment?.let { Text("${stringResource(R.string.comment)}: $it", style = MaterialTheme.typography.bodySmall) }

            if (order.status == "arrived" && waitingSeconds > 0) {
                Text("${stringResource(R.string.waiting)}: ${waitingSeconds / 60}:${"%02d".format(waitingSeconds % 60)}")
            }
            if (order.status == "in_progress" && tariff != null) {
                val fare = Fare.calculate(tariff, meters.first, meters.second, startedAt = LocalTime.now())
                Text(
                    "${"%.2f".format((meters.first + meters.second) / 1000.0)} ${stringResource(R.string.km)} · ${formatMoney(fare.total)} ${stringResource(R.string.som)}",
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.Bold,
                )
            }

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = {
                    order.client?.phone?.let { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$it"))) }
                }) { Icon(Icons.Default.Phone, null) }
                OutlinedButton(onClick = {
                    val target = if (order.status == "in_progress") order.dropoff else order.pickup
                    if (target?.lat != null) {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:${target.lat},${target.lng}?q=${target.lat},${target.lng}")))
                    }
                }) { Icon(Icons.Default.Navigation, null) }
                if (order.status in setOf("assigned", "arrived")) {
                    OutlinedButton(onClick = { cancelling = true }) { Text(stringResource(R.string.cancel_order)) }
                }
            }

            when (order.status) {
                "assigned" -> Button(onClick = { act { repository.arrive(order.id) } }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.arrived))
                }
                "arrived" -> Button(onClick = { act { repository.start(order.id) } }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.passenger_in))
                }
                "in_progress" -> Button(
                    onClick = {
                        busy = true
                        IbTaxiApp.instance.scope.launch {
                            when (val result = repository.complete(order.id, meters.first, meters.second)) {
                                is Result.Ok -> DriverState.receipt(result.value?.priceFinal)
                                is Result.Error -> DriverState.toast(result.message ?: context.getString(R.string.network_error))
                            }
                            busy = false
                        }
                    },
                    enabled = !busy,
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                    modifier = Modifier.fillMaxWidth(),
                ) { Text(stringResource(R.string.finish)) }
            }
        }
    }

    if (cancelling) CancelDialog(onDismiss = { cancelling = false }) { reason ->
        cancelling = false
        act { repository.cancel(order.id, reason) }
    }
}

@Composable
private fun CancelDialog(onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var reason by remember { mutableStateOf("") }
    val quick = listOf(stringResource(R.string.quick_client_late), stringResource(R.string.quick_address))

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.cancel_reason)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                quick.forEach { option -> OutlinedButton(onClick = { reason = option }, modifier = Modifier.fillMaxWidth()) { Text(option) } }
                OutlinedTextField(value = reason, onValueChange = { reason = it }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = { Button(onClick = { onConfirm(reason) }, enabled = reason.isNotBlank()) { Text(stringResource(R.string.cancel_order)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("✕") } },
    )
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
