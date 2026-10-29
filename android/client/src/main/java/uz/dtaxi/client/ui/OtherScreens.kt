package uz.dtaxi.client.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.Profile
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.Formats
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.DeleteOutline
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Language
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material.icons.filled.Work
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp
import uz.dtaxi.client.data.SavedAddress
import uz.dtaxi.core.ui.BigButton
import uz.dtaxi.core.ui.LanguageSetting
import uz.dtaxi.core.ui.SegmentedChoice
import uz.dtaxi.core.ui.SettingsGroup
import uz.dtaxi.core.ui.SettingsItem
import uz.dtaxi.core.ui.StatusPill
import uz.dtaxi.core.ui.TileTextField
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.formatMoney
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** Safardan keyin baho (1–5) — haydovchi reytingiga ta'sir qiladi */
@Composable
fun RateDialog(order: ClientOrder) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var rating by remember { mutableStateOf(0) }
    // Ikki marta bosilsa ikkita so'rov ketmasin
    var sending by remember { mutableStateOf(false) }
    AlertDialog(
        // Oyna tashqarisiga bosish ham "Keyinroq" — keyingi ochilishda qayta chiqmaydi
        onDismissRequest = { if (!sending) repository.dismissRating(order.id) },
        title = { Text(stringResource(R.string.rate_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                order.priceFinal?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }
                Row {
                    (1..5).forEach { star ->
                        IconButton(onClick = { rating = star }, enabled = !sending) {
                            Icon(
                                if (star <= rating) Icons.Default.Star else Icons.Default.StarBorder,
                                contentDescription = star.toString(),
                                tint = MaterialTheme.colorScheme.primary,
                            )
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                sending = true
                scope.launch { if (repository.rate(order.id, rating) is Result.Error) sending = false }
            }, enabled = rating > 0 && !sending) { Text(stringResource(R.string.save)) }
        },
        dismissButton = { TextButton(onClick = { repository.dismissRating(order.id) }, enabled = !sending) { Text(stringResource(R.string.later)) } },
    )
}

/** Server sahifasi hajmi (OrderController::index) — kamroq kelsa oxiri */
private const val HISTORY_PAGE = 20

private val HISTORY_TIME: DateTimeFormatter = DateTimeFormatter.ofPattern("dd.MM.yyyy HH:mm")

/** Server vaqti (ISO 8601, filial mintaqasida) — telefonning mahalliy vaqtida */
internal fun localTime(iso: String): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(HISTORY_TIME)
}.getOrElse { iso.take(16).replace('T', ' ') }

/** canReorder — faol buyurtma yo'q bo'lsa "Qayta buyurtma" (manzillar va tarif qoralamaga) */
@Composable
fun HistoryScreen(onBack: () -> Unit, canReorder: Boolean, onReorder: (ClientOrder) -> Unit) {
    val repository = ClientApp.instance.repository
    var orders by remember { mutableStateOf<List<ClientOrder>?>(null) }
    var end by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    BackHandler(onBack = onBack)

    // Keyingi sahifa: ro'yxat oxiri ko'ringanda (pastdagi yuklash qatori chizilganda)
    suspend fun loadMore() {
        val loaded = orders.orEmpty()
        when (val result = repository.history(loaded.lastOrNull()?.id)) {
            is Result.Ok -> {
                orders = loaded + result.value.filter { new -> loaded.none { it.id == new.id } }
                end = result.value.size < HISTORY_PAGE
            }
            is Result.Error -> failed = true
        }
    }

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TopRow(stringResource(R.string.history), onBack)
        val list = orders
        if (end && list != null && list.isEmpty()) {
            Text(stringResource(R.string.history_empty), Modifier.padding(16.dp), color = LocalAppColors.current.muted)
        }
        LazyColumn(
            Modifier.navigationBarsPadding(),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            items(list.orEmpty(), key = { it.id }) { order ->
                OrderCard(order, onReorder = if (canReorder && !order.isActive) ({ onReorder(order) }) else null)
            }
            if (!end) {
                item(key = "more") {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        if (failed) {
                            TextButton(onClick = {
                                failed = false
                                attempt++
                            }) { Text(stringResource(R.string.network_error)) }
                        } else {
                            CircularProgressIndicator(Modifier.size(24.dp))
                            LaunchedEffect(list?.size, attempt) { loadMore() }
                        }
                    }
                }
            }
        }
    }
}

/** Safar kartochkasi (haydovchi ilovasidagi bilan bir xil tuzilish): sana va holat, A→B, mashina, narx */
@Composable
private fun OrderCard(order: ClientOrder, onReorder: (() -> Unit)?) {
    val colors = LocalAppColors.current
    val completed = order.status == "completed"
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(colors.tile).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(localTime(order.createdAt), Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, color = colors.muted)
            StatusPill(statusText(order.status), ok = if (completed || order.isActive) true else null)
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            RoutePoint(StartGreen, order.pickup.text.orEmpty())
            order.dropoff?.text?.let { RoutePoint(FinishRed, it) }
        }
        order.vehicle?.let { v ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(listOf(v.model.ifBlank { v.brand }, v.color).filter { it.isNotBlank() }.joinToString(" · "), color = colors.muted, maxLines = 1)
                Text(
                    v.plate,
                    Modifier.border(1.5.dp, MaterialTheme.colorScheme.onSurface, RoundedCornerShape(5.dp)).padding(horizontal = 6.dp, vertical = 1.dp),
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
        // Bekor qilinganda narx ko'rsatilmaydi — mijoz to'lamagan
        val price = if (completed) order.priceFinal ?: order.priceEstimate else null
        if (price != null || order.rating != null) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(order.rating?.let { "★".repeat(it) }.orEmpty(), Modifier.weight(1f), color = Color(0xFFF2A900))
                price?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.ExtraBold, style = MaterialTheme.typography.titleMedium) }
            }
        }
        onReorder?.let {
            Button(
                onClick = it,
                shape = RoundedCornerShape(12.dp),
                colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.onSurface, contentColor = MaterialTheme.colorScheme.surface),
                modifier = Modifier.height(42.dp),
            ) { Text(stringResource(R.string.order_again), fontWeight = FontWeight.SemiBold) }
        }
    }
}

private val StartGreen = Color(0xFF1F8A4C)
private val FinishRed = Color(0xFFD93025)

@Composable
private fun RoutePoint(dot: Color, text: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.size(10.dp).clip(CircleShape).background(dot))
        Text(text, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/**
 * Sozlamalar: profil (ism shu yerda yoziladi/o'zgaradi), saqlangan manzillar, til va mavzu, yordam;
 * pastda chiqish va akkauntni o'chirish (Play talabi — kichik havola).
 */
@Composable
fun SettingsScreen(
    profile: Profile,
    onLocaleChange: (String) -> Unit,
    onThemeChange: (String) -> Unit,
    onAddAddress: () -> Unit,
    onBack: () -> Unit,
) {
    val repository = ClientApp.instance.repository
    val session = ClientApp.instance.session
    val scope = rememberCoroutineScope()
    var confirmLogout by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    var editingName by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf<SavedAddress?>(null) }
    var addresses by remember { mutableStateOf<List<SavedAddress>>(emptyList()) }
    val context = LocalContext.current
    var theme by remember { mutableStateOf(session.themeMode) }
    val colors = LocalAppColors.current
    BackHandler(onBack = onBack)
    LaunchedEffect(Unit) { (repository.addresses() as? Result.Ok)?.let { addresses = it.value } }

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TopRow(stringResource(R.string.settings), onBack)
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).navigationBarsPadding().padding(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            // Profil: bosilsa ism o'zgaradi (kirishda o'tkazib yuborilgan bo'lsa ham shu yerda yoziladi)
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(colors.tile).clickable { editingName = true }.padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                val name = profile.name?.takeIf { it.isNotBlank() }
                Box(Modifier.size(52.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primary), contentAlignment = Alignment.Center) {
                    if (name != null) {
                        Text(name.first().uppercase(), color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 20.sp)
                    } else {
                        Icon(Icons.Default.Person, contentDescription = null, tint = MaterialTheme.colorScheme.onPrimary)
                    }
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        name ?: stringResource(R.string.name_placeholder),
                        fontWeight = FontWeight.Bold,
                        fontSize = 17.sp,
                        color = if (name == null) colors.muted else Color.Unspecified,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text(Formats.formatPhone(profile.phone), color = colors.muted)
                }
                Icon(Icons.Default.Edit, contentDescription = stringResource(R.string.your_name), tint = colors.muted)
            }

            SettingsGroup(stringResource(R.string.my_addresses)) {
                addresses.forEach { address ->
                    SettingsItem(
                        addressIcon(address.label),
                        address.label ?: address.text,
                        subtitle = address.label?.let { address.text },
                        trailing = {
                            IconButton(onClick = { deleting = address }) {
                                Icon(Icons.Default.DeleteOutline, stringResource(R.string.delete), tint = colors.muted)
                            }
                        },
                    )
                }
                SettingsItem(Icons.Default.Add, stringResource(R.string.add_address), onClick = onAddAddress)
            }

            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                SettingsGroup(stringResource(R.string.settings_app)) {
                    LanguageSetting(Icons.Default.Language, stringResource(R.string.language), session.locale) { if (session.locale != it) onLocaleChange(it) }
                }
                SegmentedChoice(
                    listOf(
                        "system" to stringResource(R.string.theme_system),
                        "light" to stringResource(R.string.theme_light),
                        "dark" to stringResource(R.string.theme_dark),
                    ),
                    selected = theme,
                    onSelect = {
                        theme = it
                        onThemeChange(it)
                    },
                )
            }

            SettingsGroup(stringResource(R.string.settings_help)) {
                profile.config.supportPhone?.takeIf { it.isNotBlank() }?.let { phone ->
                    SettingsItem(
                        Icons.Default.Call,
                        stringResource(R.string.settings_call_dispatcher),
                        subtitle = Formats.formatPhone(phone),
                        onClick = { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } },
                    )
                }
                SettingsItem(Icons.Default.Shield, stringResource(R.string.privacy_policy), onClick = { openPrivacy(context) })
            }

            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Button(
                    onClick = { confirmLogout = true },
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(16.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = colors.tile, contentColor = MaterialTheme.colorScheme.error),
                ) { Text(stringResource(R.string.logout), fontWeight = FontWeight.Bold) }
                TextButton(onClick = { confirmDelete = true }) {
                    Text(stringResource(R.string.delete_account), color = colors.muted, textDecoration = TextDecoration.Underline)
                }
                Text(stringResource(R.string.app_version, BuildConfig.VERSION_NAME), style = MaterialTheme.typography.bodySmall, color = colors.muted)
            }
        }
    }

    if (editingName) {
        NameSheet(profile.name.orEmpty(), onDismiss = { editingName = false })
    }

    deleting?.let { address ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text(stringResource(R.string.delete_address_confirm)) },
            text = { Text(address.label?.let { "$it — ${address.text}" } ?: address.text) },
            confirmButton = {
                TextButton(onClick = {
                    deleting = null
                    scope.launch { if (repository.deleteAddress(address.id) is Result.Ok) addresses = addresses.filterNot { it.id == address.id } }
                }) { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { deleting = null }) { Text(stringResource(R.string.cancel)) } },
        )
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text(stringResource(R.string.delete_account)) },
            text = { Text(stringResource(R.string.delete_account_text)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    scope.launch { repository.deleteAccount() }
                }) { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }

    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text(stringResource(R.string.logout_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmLogout = false
                    scope.launch { repository.logout() }
                }) { Text(stringResource(R.string.logout)) }
            },
            dismissButton = { TextButton(onClick = { confirmLogout = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}

/** Uy / Ish — o'z belgisi bilan, qolganlari yulduzcha */
@Composable
private fun addressIcon(label: String?): androidx.compose.ui.graphics.vector.ImageVector = when (label) {
    stringResource(R.string.home) -> Icons.Default.Home
    stringResource(R.string.work) -> Icons.Default.Work
    else -> Icons.Default.Star
}

/** Ismni yozish/o'zgartirish: pastdan varaq, saqlansa profil yangilanadi */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NameSheet(current: String, onDismiss: () -> Unit) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(current) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val generic = stringResource(R.string.error)

    fun save() {
        if (loading || name.isBlank()) return
        loading = true
        scope.launch {
            when (val result = repository.updateProfile(name = name.trim())) {
                is Result.Ok -> onDismiss()
                is Result.Error -> error = result.message ?: generic
            }
            loading = false
        }
    }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = MaterialTheme.colorScheme.surface,
    ) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp).navigationBarsPadding().imePadding().padding(bottom = 16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.your_name), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            TileTextField(
                value = name,
                onValueChange = { name = cleanName(it) },
                placeholder = stringResource(R.string.name_placeholder),
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { save() }),
            )
            Text(stringResource(R.string.name_hint), style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            BigButton(stringResource(R.string.save), onClick = ::save, enabled = name.isNotBlank() && name.trim() != current, loading = loading)
        }
    }
}

/** Maxfiylik siyosati — firma serverida (Google Play'da ham shu manzil) */
fun openPrivacy(context: Context) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.SERVER_URL + "/privacy"))) }
}

@Composable
private fun TopRow(title: String, onBack: () -> Unit) {
    Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
        Text(title, style = MaterialTheme.typography.titleLarge)
    }
}
