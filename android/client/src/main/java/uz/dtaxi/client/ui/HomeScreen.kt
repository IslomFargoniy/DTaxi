package uz.dtaxi.client.ui

import android.Manifest
import android.app.Activity
import android.annotation.SuppressLint
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.SystemClock
import android.provider.Settings
import android.widget.Toast
import androidx.activity.compose.BackHandler
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
import androidx.compose.foundation.layout.imePadding
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
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.Job
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
import kotlin.math.ceil
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

    /** "Qayerga" xaritadan tanlanmoqda: markazdagi pin — B nuqtasi, tasdiqlanguncha dropoffCandidate da */
    var choosingDropoff by mutableStateOf(false)
    var dropoffCandidate by mutableStateOf<Place?>(null)

    /** Safar tugagach yoki chiqishda — keyingi buyurtma toza boshlanadi (tarif tanlovi qoladi) */
    fun reset() {
        pickup = null
        dropoff = null
        comment = ""
        pickupFromSearch = false
        choosingDropoff = false
        dropoffCandidate = null
    }
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
    val live by ClientState.live.collectAsState()
    var locating by remember { mutableStateOf(false) }
    var myLocation by remember { mutableStateOf<LatLng?>(null) }
    // Teskari geokodlash — bitta so'rov: xarita yana surilsa oldingisi bekor (eski javob yangisini ustidan yozmaydi)
    val geocodeJob = remember { arrayOfNulls<Job>(1) }
    var openSettings by remember { mutableStateOf(false) }
    // Foydalanuvchi xaritani qo'lda sursa — kamera biroz vaqt haydovchiga ergashmaydi
    var followPausedUntil by remember { mutableLongStateOf(0L) }
    val pointOnMap = stringResource(R.string.point_on_map)
    var menu by remember { mutableStateOf(false) }
    val network = stringResource(R.string.network_error)
    val generic = stringResource(R.string.error)
    val denied = stringResource(R.string.location_denied)
    val tooMany = stringResource(R.string.too_many_requests)
    var notice by remember { mutableStateOf<String?>(null) }
    // Xarita ustidagi panellar balandligi (dp) — pin va kamera ko'rinadigan maydon markazida
    val density = LocalDensity.current
    var topInset by remember { mutableStateOf(0.dp) }
    var bottomInset by remember { mutableStateOf(0.dp) }

    suspend fun locate(): Boolean {
        val point = currentLocation(context) ?: return false
        myLocation = point
        if (ClientState.order.value == null) {
            draft.pickupFromSearch = false
            controller.moveTo(point)
        }
        return true
    }

    fun geocode(center: LatLng, apply: (String?) -> Unit) {
        geocodeJob[0]?.cancel()
        locating = true
        geocodeJob[0] = scope.launch {
            apply((repository.reverse(center.lat, center.lng) as? Result.Ok)?.value?.takeIf { it.isNotBlank() })
            locating = false
        }
    }

    // "Qayerdan": xarita markazi
    fun pickAt(center: LatLng) {
        draft.pickup?.let { current ->
            val moved = distanceMeters(center, LatLng(current.lat, current.lng))
            // Panel balandligi o'zgarsa (klaviatura, tarif qatori) markaz joyida qoladi — manzil qayta aniqlanmaydi
            if (moved < 2 || (draft.pickupFromSearch && moved < 25)) return
        }
        draft.pickupFromSearch = false
        draft.pickup = Place(null, center.lat, center.lng)
        geocode(center) { name ->
            // Bu orada xarita yana surilgan bo'lsa — eski javob yozilmaydi
            if (draft.pickup?.lat == center.lat && draft.pickup?.lng == center.lng) draft.pickup = Place(name, center.lat, center.lng)
        }
    }

    // "Qayerga" xaritadan
    fun pickDropoffAt(center: LatLng) {
        draft.dropoffCandidate?.let { if (distanceMeters(center, LatLng(it.lat, it.lng)) < 2) return }
        draft.dropoffCandidate = Place(null, center.lat, center.lng)
        geocode(center) { name ->
            val candidate = draft.dropoffCandidate
            if (candidate?.lat == center.lat && candidate.lng == center.lng) draft.dropoffCandidate = Place(name, center.lat, center.lng)
        }
    }

    fun finishChoosingDropoff(confirm: Boolean) {
        if (confirm) draft.dropoffCandidate?.let { draft.dropoff = if (it.text.isNullOrBlank()) it.copy(text = pointOnMap) else it }
        draft.choosingDropoff = false
        draft.dropoffCandidate = null
        draft.pickup?.let { controller.moveTo(LatLng(it.lat, it.lng)) }
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

    // Tugma bosib so'ralganmi — ilova ochilishidagi rad etishda sozlamalar oynasi chiqmasin
    val askedByUser = remember { booleanArrayOf(false) }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) {
            notice = null
            scope.launch { locate() }
        } else {
            notice = denied
            // "Boshqa so'ramaslik" tanlangan — tizim oynasi endi chiqmaydi, faqat ilova sozlamalaridan yoqiladi
            val activity = context.findActivity()
            if (askedByUser[0] && activity != null && LOCATION_PERMISSIONS.none(activity::shouldShowRequestPermissionRationale)) openSettings = true
        }
        askedByUser[0] = false
        askNotifications()
    }

    LaunchedEffect(Unit) {
        if (hasLocation(context)) {
            askNotifications()
            locate()
        } else {
            permission.launch(LOCATION_PERMISSIONS)
        }
    }

    // Faol buyurtma — Reverb uzilsa ham holat yangilanib tursin; uzilgan paytda tezroq (haydovchi belgisi shu orqali yuradi)
    LaunchedEffect(order?.id, live) {
        while (order != null) {
            delay(if (live) 15_000 else 5_000)
            repository.refreshCurrent()
        }
    }

    val activeOrder = order
    // Buyurtma tugadi (yakunlandi, bekor qilindi): qoralama tozalanadi, "Qayerdan" — yana joriy joy
    val lastOrderId = remember { intArrayOf(0) }
    LaunchedEffect(activeOrder?.id) {
        val id = activeOrder?.id
        if (id != null) {
            draft.choosingDropoff = false
        } else if (lastOrderId[0] != 0) {
            draft.reset()
            // Joylashuv yo'q — xarita turgan joy "Qayerdan" bo'ladi (xarita surilmaydi, onCenter kelmaydi)
            if (!locate()) controller.center()?.let(::pickAt)
        }
        lastOrderId[0] = id ?: 0
    }

    // Kamera safar davomida: haydovchi kelguncha — haydovchi va A, safarda — haydovchi va B
    val followKey = remember { arrayOfNulls<String>(1) }
    LaunchedEffect(activeOrder?.id, activeOrder?.status, driver) {
        if (activeOrder == null) {
            followKey[0] = null
            return@LaunchedEffect
        }
        val key = "${activeOrder.id}:${activeOrder.status}"
        if (followKey[0] != key) {
            // Holat o'zgardi — qo'lda surilgan bo'lsa ham yangi manzarani ko'rsatamiz
            followKey[0] = key
            followPausedUntil = 0L
        } else if (SystemClock.elapsedRealtime() < followPausedUntil) {
            return@LaunchedEffect
        }
        val car = driver?.let { LatLng(it.lat, it.lng) }
        val target = if (activeOrder.status == "in_progress") activeOrder.dropoff else activeOrder.pickup
        controller.fit(listOfNotNull(car, target?.let { LatLng(it.lat, it.lng) }))
    }

    BackHandler(enabled = draft.choosingDropoff && activeOrder == null) { finishChoosingDropoff(confirm = false) }

    Box(Modifier.fillMaxSize()) {
        ClientMap(
            styleUrl = mapStyleFor(profile.config.mapStyle, dark),
            initialCenter = profile.config.mapCenter,
            controller = controller,
            picking = activeOrder == null,
            pinColor = if (draft.choosingDropoff && activeOrder == null) MaterialTheme.colorScheme.error else null,
            pickup = (activeOrder?.pickup ?: draft.pickup?.takeIf { draft.choosingDropoff })?.let { LatLng(it.lat, it.lng) },
            dropoff = (activeOrder?.dropoff ?: draft.dropoff?.takeUnless { draft.choosingDropoff })?.let { LatLng(it.lat, it.lng) },
            driver = driver?.let { LatLng(it.lat, it.lng) },
            modifier = Modifier.fillMaxSize(),
            topInset = topInset,
            bottomInset = bottomInset,
            onCenter = { center -> if (draft.choosingDropoff) pickDropoffAt(center) else pickAt(center) },
            onMoveStarted = { if (ClientState.order.value != null) followPausedUntil = SystemClock.elapsedRealtime() + 20_000 },
        )

        // Yuqori: menyu
        Box(Modifier.onSizeChanged { topInset = with(density) { it.height.toDp() } }.statusBarsPadding().padding(12.dp)) {
            Surface(shape = CircleShape, shadowElevation = 4.dp, color = MaterialTheme.colorScheme.surface) {
                IconButton(onClick = { menu = true }) { Icon(Icons.Default.Menu, stringResource(R.string.menu)) }
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text(stringResource(R.string.history)) }, onClick = { menu = false; onHistory() })
                DropdownMenuItem(text = { Text(stringResource(R.string.settings)) }, onClick = { menu = false; onSettings() })
            }
        }

        // imePadding: klaviatura izoh maydonini yopmasin — panel klaviatura ustiga ko'tariladi
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding()) {
            SmallFloatingActionButton(
                onClick = {
                    if (hasLocation(context)) {
                        scope.launch { locate() }
                    } else {
                        askedByUser[0] = true
                        permission.launch(LOCATION_PERMISSIONS)
                    }
                },
                modifier = Modifier.align(Alignment.End).padding(12.dp),
                containerColor = MaterialTheme.colorScheme.surface,
            ) { Icon(Icons.Default.MyLocation, stringResource(R.string.my_location)) }

            Surface(
                shape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp),
                shadowElevation = 12.dp,
                color = MaterialTheme.colorScheme.surface,
                modifier = Modifier.fillMaxWidth().onSizeChanged { bottomInset = with(density) { it.height.toDp() } },
            ) {
                Column(Modifier.navigationBarsPadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    finished?.let { FinishedBanner(it) { ClientState.clearFinished() } }
                    notice?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                    if (activeOrder != null) {
                        OrderPanel(activeOrder, driver?.let { LatLng(it.lat, it.lng) }, profile.config.supportPhone, profile.config.appCalls)
                    } else if (draft.choosingDropoff) {
                        DropoffPicker(draft.dropoffCandidate, locating, onConfirm = { finishChoosingDropoff(confirm = true) }) {
                            finishChoosingDropoff(confirm = false)
                        }
                    } else {
                        OrderForm(draft, locating, onSearch) { request ->
                            notice = null
                            when (val result = repository.createOrder(request)) {
                                is Result.Ok -> Unit
                                is Result.Error -> notice = when (result.code) {
                                    -1 -> network
                                    429 -> tooMany
                                    else -> result.message ?: generic
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Har safar uchun alohida holat (baho, yuborilmoqda)
    toRate?.let { key(it.id) { RateDialog(it) } }

    if (openSettings) {
        AlertDialog(
            onDismissRequest = { openSettings = false },
            title = { Text(stringResource(R.string.location_settings_title)) },
            text = { Text(stringResource(R.string.location_settings_text)) },
            confirmButton = {
                TextButton(onClick = {
                    openSettings = false
                    runCatching {
                        context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
                    }
                }) { Text(stringResource(R.string.open_settings)) }
            },
            dismissButton = { TextButton(onClick = { openSettings = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}

/** "Qayerga" xaritadan: tanlangan nuqta nomi, tasdiqlash yoki bekor qilish */
@Composable
private fun DropoffPicker(candidate: Place?, locating: Boolean, onConfirm: () -> Unit, onCancel: () -> Unit) {
    Text(stringResource(R.string.choose_on_map_hint), style = MaterialTheme.typography.titleMedium)
    AddressRow(
        Icons.Outlined.Flag,
        MaterialTheme.colorScheme.error,
        label = stringResource(R.string.to),
        text = when {
            locating && candidate?.text == null -> stringResource(R.string.locating)
            else -> candidate?.text ?: stringResource(R.string.point_on_map)
        },
        onClick = {},
    )
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(onClick = onCancel, modifier = Modifier.weight(1f).height(52.dp)) { Text(stringResource(R.string.cancel)) }
        Button(onClick = onConfirm, enabled = candidate != null && !locating, modifier = Modifier.weight(1f).height(52.dp)) {
            Text(stringResource(R.string.done))
        }
    }
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
            // Vaqtinchalik xato (tarmoq, juda ko'p so'rov) — oldingi narxlar qoladi; faqat "hududdan tashqari" kabi rad ko'rsatiladi
            is Result.Error -> if (result.code == 422) {
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

    val pointOnMap = stringResource(R.string.point_on_map)
    Button(
        onClick = {
            val from = draft.pickup ?: return@Button
            sending = true
            scope.launch {
                // Manzil nomisiz buyurtma bo'lmasin: geokodlash muvaffaqiyatsiz bo'lgan bo'lsa yana bir urinish, so'ng "Xaritadagi nuqta"
                val pickup = if (from.text.isNullOrBlank()) {
                    val name = (repository.reverse(from.lat, from.lng) as? Result.Ok)?.value?.takeIf { it.isNotBlank() }
                    from.copy(text = name ?: pointOnMap)
                } else {
                    from
                }
                val dropoff = draft.dropoff?.let { if (it.text.isNullOrBlank()) it.copy(text = pointOnMap) else it }
                onOrder(OrderRequest(pickup, dropoff, draft.tariffId, draft.comment.trim().ifBlank { null }))
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
private fun OrderPanel(order: ClientOrder, driver: LatLng?, supportPhone: String?, appCalls: Boolean) {
    val context = LocalContext.current
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var confirmCancel by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }

    // Ilova ichidan qo'ng'iroq (qaror #72): mikrofon ruxsati so'ralib, keyin qo'ng'iroq oynasi
    var pendingCall by remember { mutableStateOf<String?>(null) }
    val openCall = { target: String ->
        context.startActivity(CallActivity.intent(context, target, order.driver?.name.takeIf { target == "driver" }))
    }
    val micPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        val target = pendingCall
        pendingCall = null
        when {
            target == null -> Unit
            granted -> openCall(target)
            // Ruxsat berilmadi — oddiy qo'ng'iroq firma raqamiga (zaxira)
            supportPhone != null -> context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$supportPhone")))
            else -> Toast.makeText(context, R.string.mic_permission_needed, Toast.LENGTH_LONG).show()
        }
    }
    val call = { target: String ->
        if (context.checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            openCall(target)
        } else {
            pendingCall = target
            micPermission.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    Text(statusText(order.status), style = MaterialTheme.typography.titleLarge)
    if (order.status in setOf("new", "searching")) LinearProgressIndicator(Modifier.fillMaxWidth())
    if (order.status == "arrived") Text(stringResource(R.string.arrived_hint), color = LocalAppColors.current.success)
    // Taxminiy vaqt: server ETA bermaydi — haydovchi joyidan to'g'ri chiziq masofasi bo'yicha
    val etaTarget = when (order.status) {
        "assigned" -> order.pickup
        "in_progress" -> order.dropoff
        else -> null
    }
    if (driver != null && etaTarget != null) {
        val minutes = etaMinutes(driver, LatLng(etaTarget.lat, etaTarget.lng))
        Text(
            stringResource(if (order.status == "assigned") R.string.eta_pickup else R.string.eta_dropoff, minutes),
            color = LocalAppColors.current.muted,
        )
    }

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

    if (appCalls) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            // Haydovchiga — faqat u biriktirilgan va safar tugamagan paytda (server ham tekshiradi)
            if (order.driver != null && order.status in setOf("assigned", "arrived", "in_progress")) {
                OutlinedButton(onClick = { call("driver") }, modifier = Modifier.weight(1f)) {
                    Icon(Icons.Default.Call, null, Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.call_driver), maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
            OutlinedButton(onClick = { call("dispatcher") }, modifier = Modifier.weight(1f)) {
                Icon(Icons.Default.Call, null, Modifier.size(18.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.call_dispatcher), maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (!appCalls && supportPhone != null) {
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

private val LOCATION_PERMISSIONS = arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

/** Shahar ichida: yo'l to'g'ri chiziqdan ~1.4 marta uzun, o'rtacha 25 km/soat; kamida 1 daqiqa */
internal fun etaMinutes(from: LatLng, to: LatLng): Int =
    ceil(distanceMeters(from, to) * 1.4 / (25_000.0 / 60)).toInt().coerceAtLeast(1)

/** Kichik masofalar uchun yetarli (metr) */
internal fun distanceMeters(a: LatLng, b: LatLng): Double {
    val dLat = (a.lat - b.lat) * 111_320
    val dLng = (a.lng - b.lng) * 111_320 * cos(Math.toRadians(a.lat))
    return sqrt(dLat * dLat + dLng * dLng)
}
