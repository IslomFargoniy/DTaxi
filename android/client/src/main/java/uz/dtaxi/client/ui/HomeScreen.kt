package uz.dtaxi.client.ui

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.TripOrigin
import androidx.compose.material.icons.outlined.Flag
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.tasks.await
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.ClientState
import uz.dtaxi.client.data.LatLng
import uz.dtaxi.client.data.OrderRequest
import uz.dtaxi.client.data.Place
import uz.dtaxi.client.data.Profile
import uz.dtaxi.client.data.Result
import uz.dtaxi.client.data.TariffQuote
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.formatMoney
import uz.dtaxi.core.ui.mapStyleFor
import kotlin.math.cos
import kotlin.math.sqrt

/** Buyurtma qoralamasi — qidiruv ekraniga o'tib qaytganda saqlanadi */
class OrderDraft {
    var pickup by mutableStateOf<Place?>(null)
    var dropoff by mutableStateOf<Place?>(null)
    var comment by mutableStateOf("")
    var tariffId by mutableStateOf<Int?>(null)

    /** Qidiruvdan tanlangan "Qayerdan" — kamera unga borgach teskari geokodlash nomini almashtirmasin */
    var pickupFromSearch by mutableStateOf(false)
}

enum class SearchTarget { Pickup, Dropoff }

@Composable
fun HomeScreen(
    profile: Profile,
    draft: OrderDraft,
    controller: MapController,
    dark: Boolean,
    onSearch: (SearchTarget) -> Unit,
    onHistory: () -> Unit,
    onSettings: () -> Unit,
) {
    val context = LocalContext.current
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    val order by ClientState.order.collectAsState()
    val driver by ClientState.driver.collectAsState()
    val toRate by ClientState.toRate.collectAsState()
    val finished by ClientState.finished.collectAsState()
    var locating by remember { mutableStateOf(false) }
    var myLocation by remember { mutableStateOf<LatLng?>(null) }
    var menu by remember { mutableStateOf(false) }
    val network = stringResource(R.string.network_error)
    val generic = stringResource(R.string.error)
    val denied = stringResource(R.string.location_denied)
    var notice by remember { mutableStateOf<String?>(null) }

    suspend fun locate() {
        val point = currentLocation(context) ?: return
        myLocation = point
        if (ClientState.order.value == null) {
            draft.pickupFromSearch = false
            controller.moveTo(point)
        }
    }

    // Android 13+: buyurtma holati ilova yopiq paytida (push) — rad etilsa ham ilova ishlayveradi.
    // Ruxsat oynalari ketma-ket: bir vaqtda ikkitasi so'ralsa ikkinchisi chiqmay qoladi
    val notifications = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
    fun askNotifications() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            notifications.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) scope.launch { locate() } else notice = denied
        askNotifications()
    }

    LaunchedEffect(Unit) {
        if (hasLocation(context)) {
            askNotifications()
            locate()
        } else {
            permission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }
    }

    // Faol buyurtma — Reverb uzilsa ham holat yangilanib tursin
    LaunchedEffect(order?.id) {
        while (order != null) {
            delay(15_000)
            repository.refreshCurrent()
        }
    }

    // Kamera: buyurtma bo'lsa — haydovchi va manzilni ko'rsatadi
    val activeOrder = order
    LaunchedEffect(activeOrder?.id, activeOrder?.status, driver != null) {
        activeOrder ?: return@LaunchedEffect
        controller.fit(listOfNotNull(LatLng(activeOrder.pickup.lat, activeOrder.pickup.lng), driver?.let { LatLng(it.lat, it.lng) }))
    }

    Box(Modifier.fillMaxSize()) {
        ClientMap(
            styleUrl = mapStyleFor(profile.config.mapStyle, dark),
            initialCenter = profile.config.mapCenter,
            controller = controller,
            picking = activeOrder == null,
            pickup = activeOrder?.pickup?.let { LatLng(it.lat, it.lng) },
            dropoff = (activeOrder?.dropoff ?: draft.dropoff)?.let { LatLng(it.lat, it.lng) },
            driver = driver?.let { LatLng(it.lat, it.lng) },
            modifier = Modifier.fillMaxSize(),
            onCenter = { center ->
                val current = draft.pickup
                if (draft.pickupFromSearch && current != null && distanceMeters(center, LatLng(current.lat, current.lng)) < 25) return@ClientMap
                draft.pickupFromSearch = false
                draft.pickup = Place(null, center.lat, center.lng)
                locating = true
                scope.launch {
                    val name = (repository.reverse(center.lat, center.lng) as? Result.Ok)?.value
                    // Bu orada xarita yana surilgan bo'lsa — eski javob yozilmaydi
                    if (draft.pickup?.lat == center.lat && draft.pickup?.lng == center.lng) {
                        draft.pickup = Place(name, center.lat, center.lng)
                    }
                    locating = false
                }
            },
        )

        // Yuqori: menyu
        Box(Modifier.statusBarsPadding().padding(12.dp)) {
            Surface(shape = CircleShape, shadowElevation = 4.dp, color = MaterialTheme.colorScheme.surface) {
                IconButton(onClick = { menu = true }) { Icon(Icons.Default.Menu, stringResource(R.string.menu)) }
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text(stringResource(R.string.history)) }, onClick = { menu = false; onHistory() })
                DropdownMenuItem(text = { Text(stringResource(R.string.settings)) }, onClick = { menu = false; onSettings() })
            }
        }

        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
            SmallFloatingActionButton(
                onClick = { scope.launch { if (hasLocation(context)) locate() else permission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)) } },
                modifier = Modifier.align(Alignment.End).padding(12.dp),
                containerColor = MaterialTheme.colorScheme.surface,
            ) { Icon(Icons.Default.MyLocation, stringResource(R.string.my_location)) }

            Surface(
                shape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp),
                shadowElevation = 12.dp,
                color = MaterialTheme.colorScheme.surface,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.navigationBarsPadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    finished?.let { FinishedBanner(it) { ClientState.clearFinished() } }
                    notice?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                    if (activeOrder != null) {
                        OrderPanel(activeOrder, profile.config.supportPhone)
                    } else {
                        OrderForm(draft, locating, onSearch) { request ->
                            notice = null
                            when (val result = repository.createOrder(request)) {
                                is Result.Ok -> Unit
                                is Result.Error -> notice = if (result.code == -1) network else (result.message ?: generic)
                            }
                        }
                    }
                }
            }
        }
    }

    toRate?.let { RateDialog(it) }
}

@Composable
private fun OrderForm(draft: OrderDraft, locating: Boolean, onSearch: (SearchTarget) -> Unit, onOrder: suspend (OrderRequest) -> Unit) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var tariffs by remember { mutableStateOf<List<TariffQuote>>(emptyList()) }
    var quoteError by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    var showComment by remember { mutableStateOf(draft.comment.isNotEmpty()) }
    val pickup = draft.pickup

    // Narx: manzillar o'zgargach (xarita surilishi tugagach) — tarif klasslari bo'yicha
    LaunchedEffect(pickup?.lat, pickup?.lng, draft.dropoff?.lat, draft.dropoff?.lng) {
        pickup ?: return@LaunchedEffect
        delay(400)
        when (val result = repository.quote(pickup, draft.dropoff)) {
            is Result.Ok -> {
                tariffs = result.value.tariffs
                quoteError = null
                if (tariffs.none { it.id == draft.tariffId }) draft.tariffId = tariffs.firstOrNull()?.id
            }
            is Result.Error -> {
                tariffs = emptyList()
                quoteError = result.message
            }
        }
    }

    AddressRow(
        Icons.Default.TripOrigin,
        LocalAppColors.current.success,
        label = stringResource(R.string.from),
        text = when {
            locating && pickup?.text == null -> stringResource(R.string.locating)
            else -> pickup?.text ?: stringResource(R.string.point_on_map)
        },
        onClick = { onSearch(SearchTarget.Pickup) },
    )
    HorizontalDivider()
    AddressRow(
        Icons.Outlined.Flag,
        MaterialTheme.colorScheme.error,
        label = stringResource(R.string.to),
        text = draft.dropoff?.text ?: stringResource(R.string.to_hint),
        muted = draft.dropoff == null,
        onClick = { onSearch(SearchTarget.Dropoff) },
        onClear = draft.dropoff?.let { { draft.dropoff = null } },
    )

    quoteError?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
    if (tariffs.isNotEmpty()) {
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(tariffs, key = { it.id }) { tariff ->
                FilterChip(
                    selected = tariff.id == draft.tariffId,
                    onClick = { draft.tariffId = tariff.id },
                    label = {
                        Column(Modifier.padding(vertical = 6.dp)) {
                            Text(tariff.name, fontWeight = FontWeight.Bold)
                            Text(
                                tariff.price?.let { stringResource(R.string.price, formatMoney(it)) }
                                    ?: stringResource(R.string.price_from, formatMoney(tariff.minPrice)),
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    },
                )
            }
        }
    }

    if (showComment) {
        OutlinedTextField(
            value = draft.comment,
            onValueChange = { draft.comment = it.take(300) },
            label = { Text(stringResource(R.string.comment)) },
            modifier = Modifier.fillMaxWidth(),
        )
    } else {
        TextButton(onClick = { showComment = true }) { Text(stringResource(R.string.comment)) }
    }

    Button(
        onClick = {
            val from = draft.pickup ?: return@Button
            sending = true
            scope.launch {
                onOrder(OrderRequest(from, draft.dropoff, draft.tariffId, draft.comment.trim().ifBlank { null }))
                sending = false
            }
        },
        enabled = !sending && !locating && pickup != null && tariffs.isNotEmpty(),
        modifier = Modifier.fillMaxWidth().height(52.dp),
    ) {
        if (sending) CircularProgressIndicator(Modifier.size(20.dp)) else Text(stringResource(R.string.order))
    }
}

@Composable
private fun AddressRow(
    icon: ImageVector,
    tint: androidx.compose.ui.graphics.Color,
    label: String,
    text: String,
    onClick: () -> Unit,
    muted: Boolean = false,
    onClear: (() -> Unit)? = null,
) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.labelSmall, color = LocalAppColors.current.muted)
            Text(
                text,
                style = MaterialTheme.typography.bodyLarge,
                color = if (muted) LocalAppColors.current.muted else MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        onClear?.let { IconButton(onClick = it) { Icon(Icons.Default.Close, stringResource(R.string.delete)) } }
    }
}

/** Faol buyurtma: holat, haydovchi va mashina, aloqa, bekor qilish */
@Composable
private fun OrderPanel(order: ClientOrder, supportPhone: String?) {
    val context = LocalContext.current
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var confirmCancel by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }

    Text(statusText(order.status), style = MaterialTheme.typography.titleLarge)
    if (order.status in setOf("new", "searching")) LinearProgressIndicator(Modifier.fillMaxWidth())
    if (order.status == "arrived") Text(stringResource(R.string.arrived_hint), color = LocalAppColors.current.success)

    order.vehicle?.let { car ->
        Surface(shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceVariant, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("${car.color} ${car.brand} ${car.model}", style = MaterialTheme.typography.titleMedium)
                Text(car.plate, style = MaterialTheme.typography.headlineSmall)
                order.driver?.let { Text("${it.name} · " + stringResource(R.string.callsign, it.callsign), color = LocalAppColors.current.muted) }
            }
        }
    }
    Text("${order.pickup.text.orEmpty()}${order.dropoff?.text?.let { " → $it" } ?: ""}", maxLines = 2, overflow = TextOverflow.Ellipsis)
    order.priceEstimate?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }

    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (supportPhone != null) {
            OutlinedButton(
                onClick = { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$supportPhone"))) },
                modifier = Modifier.weight(1f),
            ) {
                Icon(Icons.Default.Call, null, Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.call))
            }
        }
        if (order.canCancel) {
            OutlinedButton(onClick = { confirmCancel = true }, enabled = !cancelling, modifier = Modifier.weight(1f)) {
                Text(stringResource(R.string.cancel_order))
            }
        }
    }

    if (confirmCancel) {
        AlertDialog(
            onDismissRequest = { confirmCancel = false },
            title = { Text(stringResource(R.string.cancel_order_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmCancel = false
                    cancelling = true
                    scope.launch {
                        repository.cancel(order.id)
                        cancelling = false
                    }
                }) { Text(stringResource(R.string.yes_cancel)) }
            },
            dismissButton = { TextButton(onClick = { confirmCancel = false }) { Text(stringResource(R.string.no)) } },
        )
    }
}

@Composable
private fun FinishedBanner(order: ClientOrder, onClose: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(statusText(order.status), Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
        IconButton(onClick = onClose) { Icon(Icons.Default.Close, stringResource(R.string.close)) }
    }
}

@Composable
fun statusText(status: String): String = stringResource(
    when (status) {
        "new", "searching" -> R.string.status_searching
        "assigned" -> R.string.status_assigned
        "arrived" -> R.string.status_arrived
        "in_progress" -> R.string.status_in_progress
        "completed" -> R.string.status_completed
        "no_driver_found" -> R.string.status_no_driver
        else -> R.string.status_cancelled
    },
)

private fun hasLocation(context: Context) =
    context.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        context.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

@SuppressLint("MissingPermission")
private suspend fun currentLocation(context: Context): LatLng? {
    if (!hasLocation(context)) return null
    return runCatching {
        LocationServices.getFusedLocationProviderClient(context)
            .getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null).await()
            ?.let { LatLng(it.latitude, it.longitude) }
    }.getOrNull()
}

/** Kichik masofalar uchun yetarli (metr) */
private fun distanceMeters(a: LatLng, b: LatLng): Double {
    val dLat = (a.lat - b.lat) * 111_320
    val dLng = (a.lng - b.lng) * 111_320 * cos(Math.toRadians(a.lat))
    return sqrt(dLat * dLat + dLng * dLng)
}
