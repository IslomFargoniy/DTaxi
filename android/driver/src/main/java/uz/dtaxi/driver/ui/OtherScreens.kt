package uz.dtaxi.driver.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.filled.BatteryChargingFull
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.DirectionsCar
import androidx.compose.material.icons.filled.Language
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Navigation
import androidx.compose.material.icons.filled.NotificationsActive
import androidx.compose.material.icons.filled.WarningAmber
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.ui.unit.sp
import uz.dtaxi.core.ui.LanguageSetting
import uz.dtaxi.core.ui.SegmentedChoice
import uz.dtaxi.core.ui.SettingsGroup
import uz.dtaxi.core.ui.SettingsItem
import uz.dtaxi.core.ui.StatusPill
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.formatMoney

import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.Spacer
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.size
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import uz.dtaxi.driver.data.PaymentsConfig
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.data.ChatMessage
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.Result
import uz.dtaxi.driver.data.Transaction
import uz.dtaxi.driver.service.DriverService

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ScreenScaffold(
    title: String,
    onBack: () -> Unit,
    header: (@Composable () -> Unit)? = null,
    content: @Composable (Modifier) -> Unit,
) {
    Scaffold(topBar = {
        Column {
            TopAppBar(title = { Text(title) }, navigationIcon = {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
            })
            header?.let { Box(Modifier.padding(horizontal = 16.dp).padding(bottom = 8.dp)) { it() } }
        }
    }) { padding -> content(Modifier.padding(padding)) }
}

/**
 * Hisobim: Balans va Safarlar bitta ekranda, tepada almashtirgich (ikkalasi ham bosh ekrandan to'g'ridan-to'g'ri ochiladi).
 */
@Composable
fun AccountScreen(onBack: () -> Unit, initialTab: String) {
    var tab by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf(initialTab) }
    val tabs: @Composable () -> Unit = {
        SegmentedChoice(
            listOf(ACCOUNT_BALANCE to stringResource(R.string.balance), ACCOUNT_TRIPS to stringResource(R.string.trips)),
            selected = tab,
            onSelect = { tab = it },
        )
    }
    if (tab == ACCOUNT_TRIPS) {
        OrderHistoryScreen(onBack = onBack, title = stringResource(R.string.account), header = tabs)
    } else {
        BalanceScreen(onBack = onBack, header = tabs)
    }
}

const val ACCOUNT_BALANCE = "balance"
const val ACCOUNT_TRIPS = "trips"

/**
 * Balans: tepada summa va ID, minimal balansdan past bo'lsa ogohlantirish; to'ldirish (qaror #23); harakatlar kunlar bo'yicha.
 */
@Composable
private fun BalanceScreen(onBack: () -> Unit, header: @Composable () -> Unit) {
    val repository = DtaxiApp.instance.repository
    val profile by DriverState.profile.collectAsState()
    var items by remember { mutableStateOf<List<Transaction>?>(null) }
    LaunchedEffect(Unit) { items = (repository.balance() as? Result.Ok)?.value?.items ?: emptyList() }
    val colors = LocalAppColors.current

    ScreenScaffold(stringResource(R.string.account), onBack, header) { modifier ->
        LazyColumn(
            modifier.fillMaxSize(),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            profile?.let { p ->
                item { BalanceCard(p.balance, p.minBalance, p.callsign) }
                item { TopUpBlock(p.callsign, p.config.payments) }
            }
            val list = items
            when {
                list == null -> item {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        androidx.compose.material3.CircularProgressIndicator(Modifier.size(28.dp))
                    }
                }
                list.isEmpty() -> item { Text(stringResource(R.string.tx_empty), color = colors.muted, modifier = Modifier.padding(start = 4.dp)) }
                else -> list.groupBy { localDate(it.createdAt) }.forEach { (day, group) ->
                    item(key = "day-$day") {
                        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text(
                                dayLabel(day).uppercase(),
                                Modifier.padding(start = 4.dp),
                                style = MaterialTheme.typography.labelMedium,
                                fontWeight = FontWeight.Bold,
                                color = colors.muted,
                            )
                            Column(
                                Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(MaterialTheme.colorScheme.outlineVariant),
                                verticalArrangement = Arrangement.spacedBy(1.dp),
                            ) {
                                group.forEach { TransactionRow(it) }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun BalanceCard(balance: Long, minBalance: Long, callsign: String) {
    val dark = MaterialTheme.colorScheme.onSurface
    val light = MaterialTheme.colorScheme.surface
    val clipboard = LocalClipboardManager.current
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            delay(1500)
            copied = false
        }
    }
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(dark).padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Column {
            Text(stringResource(R.string.balance), color = light.copy(alpha = 0.7f), style = MaterialTheme.typography.bodyMedium)
            Row(verticalAlignment = Alignment.Bottom) {
                Text(formatMoney(balance), color = light, fontSize = 34.sp, fontWeight = FontWeight.Bold)
                Text(" " + stringResource(R.string.som), color = light.copy(alpha = 0.7f), fontSize = 16.sp, modifier = Modifier.padding(bottom = 5.dp))
            }
        }
        // Haydovchi nega buyurtma kelmayotganini ko'rsin (dispatcher minimal balansdan pastga taklif yubormaydi)
        if (balance < minBalance) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(MaterialTheme.colorScheme.primary).padding(horizontal = 10.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Icon(Icons.Default.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.onPrimary, modifier = Modifier.size(20.dp))
                Text(
                    stringResource(R.string.min_balance_warning, formatMoney(minBalance)),
                    color = MaterialTheme.colorScheme.onPrimary,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Medium,
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.your_id), color = light.copy(alpha = 0.8f))
            Text(callsign, color = light, fontWeight = FontWeight.Bold, fontSize = 16.sp)
            Spacer(Modifier.weight(1f))
            OutlinedButton(
                onClick = {
                    clipboard.setText(AnnotatedString(callsign))
                    copied = true
                },
                shape = RoundedCornerShape(10.dp),
                border = androidx.compose.foundation.BorderStroke(1.dp, light.copy(alpha = 0.6f)),
                colors = androidx.compose.material3.ButtonDefaults.outlinedButtonColors(contentColor = light),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 12.dp),
                modifier = Modifier.height(34.dp),
            ) { Text(stringResource(if (copied) R.string.copied else R.string.copy)) }
        }
    }
}

/**
 * Balansni to'ldirish (qaror #23): summa tanlanadi, Click/Payme ilovasi (yoki sayti) ID bilan ochiladi.
 * Firmada ikkalasi ham ulanmagan bo'lsa — faqat kassa yo'riqnomasi.
 */
@Composable
private fun TopUpBlock(callsign: String, payments: PaymentsConfig?) {
    val context = LocalContext.current
    val colors = LocalAppColors.current
    var amount by remember { mutableStateOf(50_000L) }
    val open = { url: String -> runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }; Unit }

    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SettingsGroupLabel(stringResource(R.string.top_up))
        if (payments?.click == null && payments?.payme == null) {
            Text(stringResource(R.string.top_up_cash), color = colors.muted, modifier = Modifier.padding(start = 4.dp))
            return@Column
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(20_000L, 50_000L, 100_000L).forEach { value ->
                val on = amount == value
                Box(
                    Modifier
                        .weight(1f)
                        .height(46.dp)
                        .clip(RoundedCornerShape(12.dp))
                        .background(colors.tile)
                        .border(2.dp, if (on) MaterialTheme.colorScheme.onSurface else androidx.compose.ui.graphics.Color.Transparent, RoundedCornerShape(12.dp))
                        .selectable(selected = on, onClick = { amount = value }),
                    contentAlignment = Alignment.Center,
                ) { Text(formatMoney(value), fontWeight = FontWeight.SemiBold) }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            payments.click?.let { click ->
                PayButton("Click", androidx.compose.ui.graphics.Color(0xFF0A6CFF), androidx.compose.ui.graphics.Color.White) {
                    open("https://my.click.uz/services/pay?service_id=${click.serviceId}&merchant_id=${click.merchantId ?: ""}&amount=$amount&transaction_param=$callsign")
                }
            }
            payments.payme?.let { payme ->
                PayButton("Payme", androidx.compose.ui.graphics.Color(0xFF33CCCC), androidx.compose.ui.graphics.Color(0xFF06393A)) {
                    val params = "m=${payme.merchantId};ac.driver_id=$callsign;a=${amount * 100}"
                    open("https://checkout.paycom.uz/" + android.util.Base64.encodeToString(params.toByteArray(), android.util.Base64.NO_WRAP))
                }
            }
        }
    }
}

@Composable
private fun androidx.compose.foundation.layout.RowScope.PayButton(
    label: String,
    container: androidx.compose.ui.graphics.Color,
    content: androidx.compose.ui.graphics.Color,
    onClick: () -> Unit,
) {
    Button(
        onClick = onClick,
        shape = RoundedCornerShape(14.dp),
        colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = container, contentColor = content),
        modifier = Modifier.weight(1f).height(52.dp),
    ) { Text(label, fontWeight = FontWeight.Bold, fontSize = 16.sp) }
}

@Composable
private fun SettingsGroupLabel(text: String) {
    Text(
        text.uppercase(),
        Modifier.padding(start = 4.dp),
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
        color = LocalAppColors.current.muted,
    )
}

@Composable
private fun TransactionRow(t: Transaction) {
    val colors = LocalAppColors.current
    Row(
        Modifier.fillMaxWidth().background(colors.tile).padding(horizontal = 14.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Column(Modifier.weight(1f)) {
            Text(transactionLabel(t.type), fontWeight = FontWeight.Medium)
            Text(
                listOfNotNull(t.comment?.takeIf { it.isNotBlank() }, localTime(t.createdAt)).joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
                color = colors.muted,
                maxLines = 2,
            )
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(
                (if (t.amount > 0) "+" else "") + formatMoney(t.amount),
                color = if (t.amount > 0) colors.success else MaterialTheme.colorScheme.onSurface,
                fontWeight = FontWeight.Bold,
            )
            // Harakatdan keyingi balans — qayerda kamayganini kuzatish oson
            Text(formatMoney(t.balanceAfter), style = MaterialTheme.typography.bodySmall, color = colors.muted)
        }
    }
}

@Composable
private fun transactionLabel(type: String): String = when (type) {
    "commission" -> stringResource(R.string.tx_commission)
    "topup" -> stringResource(R.string.tx_topup)
    "penalty" -> stringResource(R.string.tx_penalty)
    "bonus" -> stringResource(R.string.tx_bonus)
    "corporate" -> stringResource(R.string.tx_corporate)
    "refund" -> stringResource(R.string.tx_refund)
    else -> stringResource(R.string.tx_adjustment)
}

private fun localDate(iso: String?): java.time.LocalDate? = iso?.let {
    runCatching { java.time.OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.systemDefault()).toLocalDate() }.getOrNull()
}

@Composable
private fun dayLabel(day: java.time.LocalDate?): String {
    val today = java.time.LocalDate.now()
    return when (day) {
        null -> "—"
        today -> stringResource(R.string.today)
        today.minusDays(1) -> stringResource(R.string.yesterday)
        else -> day.format(java.time.format.DateTimeFormatter.ofPattern("dd.MM.yyyy"))
    }
}

/**
 * Sozlamalar: tepada profil (admin kiritadi — faqat o'qiladi), buyurtma va navigatsiya ovozlari, ruxsatlar holati,
 * til va mavzu; pastda dispetcher, chiqish va versiya.
 */
@Composable
fun SettingsScreen(onBack: () -> Unit, onLocaleChange: (String) -> Unit) {
    val context = LocalContext.current
    val app = DtaxiApp.instance
    val profile by DriverState.profile.collectAsState()
    var confirmLogout by remember { mutableStateOf(false) }

    // Telefon sozlamasidan qaytganda ruxsatlar holati yangilansin
    var resumes by remember { mutableStateOf(0) }
    val lifecycle = androidx.compose.ui.platform.LocalLifecycleOwner.current.lifecycle
    androidx.compose.runtime.DisposableEffect(lifecycle) {
        val observer = androidx.lifecycle.LifecycleEventObserver { _, event ->
            if (event == androidx.lifecycle.Lifecycle.Event.ON_RESUME) resumes++
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    val batteryOk = remember(resumes) { isBatteryExempt(context) }
    val locationOk = remember(resumes) {
        androidx.core.content.ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION) ==
            android.content.pm.PackageManager.PERMISSION_GRANTED
    }
    val alertsOk = remember(resumes) { alertsAllowed(context) }

    ScreenScaffold(stringResource(R.string.settings), onBack) { modifier ->
        Column(
            modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            profile?.let { ProfileCard(it) }

            SettingsGroup(stringResource(R.string.settings_orders)) {
                OfferSoundPicker()
                var voice by remember { mutableStateOf(app.session.voiceGuidance) }
                SettingsItem(
                    Icons.Default.Navigation,
                    stringResource(R.string.voice_guidance),
                    subtitle = stringResource(R.string.voice_guidance_hint),
                    trailing = {
                        androidx.compose.material3.Switch(checked = voice, onCheckedChange = {
                            voice = it
                            app.session.voiceGuidance = it
                        })
                    },
                )
                if (voice) VoicePicker()
            }

            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                SettingsGroup(stringResource(R.string.settings_permissions)) {
                    PermissionItem(Icons.Default.BatteryChargingFull, stringResource(R.string.battery_title), stringResource(R.string.battery_hint), batteryOk) {
                        openBatterySettings(context)
                    }
                    PermissionItem(Icons.Default.LocationOn, stringResource(R.string.permission_location), stringResource(R.string.permission_location_hint), locationOk) {
                        openAppSettings(context)
                    }
                    PermissionItem(Icons.Default.NotificationsActive, stringResource(R.string.permission_notifications), stringResource(R.string.permission_notifications_hint), alertsOk) {
                        openAlertSettings(context)
                    }
                }
                // Xiaomi, Tecno va boshqalarda "Avtozapusk" — tizim holatini bilib bo'lmaydi, yo'riqnoma doim ko'rinadi
                Text(stringResource(R.string.battery_text), Modifier.padding(horizontal = 4.dp), style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
            }

            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                SettingsGroup(stringResource(R.string.settings_app)) {
                    LanguageSetting(Icons.Default.Language, stringResource(R.string.language), app.session.locale, onLocaleChange)
                }
                val themeMode by DriverState.themeMode.collectAsState()
                SegmentedChoice(
                    listOf(
                        "system" to stringResource(R.string.theme_system),
                        "light" to stringResource(R.string.theme_light),
                        "dark" to stringResource(R.string.theme_dark),
                    ),
                    selected = themeMode,
                    onSelect = { mode ->
                        app.session.themeMode = mode
                        DriverState.setThemeMode(mode)
                    },
                )
            }

            if (uz.dtaxi.driver.BuildConfig.DISPATCHER_PHONE.isNotBlank()) {
                SettingsGroup(null) {
                    SettingsItem(
                        Icons.Default.Call,
                        stringResource(R.string.call_dispatcher),
                        subtitle = uz.dtaxi.core.data.Formats.formatPhone(uz.dtaxi.driver.BuildConfig.DISPATCHER_PHONE),
                        onClick = {
                            runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:${uz.dtaxi.driver.BuildConfig.DISPATCHER_PHONE}"))) }
                        },
                    )
                }
            }

            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                // Chiqish — pastda, qizil; tasodifan bosilmasin — tasdiqlash so'raladi
                Button(
                    onClick = { confirmLogout = true },
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(16.dp),
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = LocalAppColors.current.tile,
                        contentColor = MaterialTheme.colorScheme.error,
                    ),
                ) {
                    Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.logout), fontWeight = FontWeight.Bold)
                }
                Text(
                    stringResource(R.string.app_version, uz.dtaxi.driver.BuildConfig.VERSION_NAME),
                    style = MaterialTheme.typography.bodySmall,
                    color = LocalAppColors.current.muted,
                )
            }
        }
    }

    if (confirmLogout) {
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text(stringResource(R.string.logout_confirm_title)) },
            text = { Text(stringResource(R.string.logout_confirm_text)) },
            confirmButton = {
                Button(
                    onClick = {
                        confirmLogout = false
                        // Ilova scope'ida: chiqqach ekran yopiladi, tozalash yarim yo'lda uzilmasin
                        app.scope.launch {
                            app.repository.setOnline(false)
                            DriverService.stop(context)
                            delay(200)
                            // GPS buferi va taksometr signOut ichida tozalanadi (token o'chirilgandan keyin)
                            app.repository.logout()
                        }
                    },
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = MaterialTheme.colorScheme.error,
                        contentColor = MaterialTheme.colorScheme.onError,
                    ),
                ) { Text(stringResource(R.string.logout)) }
            },
            dismissButton = {
                androidx.compose.material3.TextButton(onClick = { confirmLogout = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

/** Ism, telefon, pozivnoy, mashina — admin panelda o'zgartiriladi */
@Composable
private fun ProfileCard(p: uz.dtaxi.driver.data.DriverProfile) {
    val colors = LocalAppColors.current
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(colors.tile)) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(
                Modifier.size(52.dp).clip(androidx.compose.foundation.shape.CircleShape).background(MaterialTheme.colorScheme.primary),
                contentAlignment = Alignment.Center,
            ) {
                Text(initials(p.name), color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold, fontSize = 19.sp)
            }
            Column(Modifier.weight(1f)) {
                Text(p.name, fontWeight = FontWeight.Bold, fontSize = 17.sp, maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis)
                p.phone?.let { Text(uz.dtaxi.core.data.Formats.formatPhone(it), color = colors.muted) }
            }
            Text(
                "№ ${p.callsign}",
                Modifier.clip(RoundedCornerShape(8.dp)).background(MaterialTheme.colorScheme.surface).padding(horizontal = 8.dp, vertical = 4.dp),
                fontWeight = FontWeight.Bold,
            )
        }
        p.vehicle?.let { v ->
            androidx.compose.material3.HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            Row(Modifier.padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Icon(Icons.Default.DirectionsCar, contentDescription = null, modifier = Modifier.size(20.dp))
                Text(listOf(v.model.ifBlank { v.brand }, v.color).filter { it.isNotBlank() }.joinToString(" · "), Modifier.weight(1f), maxLines = 1)
                Text(
                    v.plateNumber,
                    Modifier.border(1.5.dp, MaterialTheme.colorScheme.onSurface, RoundedCornerShape(5.dp)).padding(horizontal = 6.dp, vertical = 1.dp),
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
        }
        Text(
            stringResource(R.string.profile_edit_hint),
            Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.5f)).padding(horizontal = 14.dp, vertical = 8.dp),
            style = MaterialTheme.typography.bodySmall,
            color = colors.muted,
        )
    }
}

private fun initials(name: String): String =
    name.split(' ').filter { it.isNotBlank() }.take(2).joinToString("") { it.first().uppercase() }.ifEmpty { "?" }

@Composable
private fun PermissionItem(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, subtitle: String, ok: Boolean, onFix: () -> Unit) {
    SettingsItem(
        icon,
        title,
        subtitle = subtitle,
        onClick = if (ok) null else onFix,
        trailing = { StatusPill(stringResource(if (ok) R.string.status_ready else R.string.status_fix), ok) },
    )
}

/** Taklif ekran o'chganda ham chiqishi uchun: bildirishnomalar va (Android 14+) to'liq ekran ruxsati */
private fun alertsAllowed(context: android.content.Context): Boolean {
    if (!androidx.core.app.NotificationManagerCompat.from(context).areNotificationsEnabled()) return false
    if (android.os.Build.VERSION.SDK_INT >= 34) {
        return context.getSystemService(android.app.NotificationManager::class.java).canUseFullScreenIntent()
    }
    return true
}

private fun openAlertSettings(context: android.content.Context) {
    val notificationsOn = androidx.core.app.NotificationManagerCompat.from(context).areNotificationsEnabled()
    val intent = if (notificationsOn && android.os.Build.VERSION.SDK_INT >= 34) {
        Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.fromParts("package", context.packageName, null))
    } else {
        Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
    }
    runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.onFailure { openAppSettings(context) }
}

private fun openAppSettings(context: android.content.Context) {
    runCatching {
        context.startActivity(
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }
}

/**
 * Profil yuklanmoqda yoki server bilan aloqa yo'q. Sabab oddiy tilda (internet yo'q / server javob bermayapti),
 * ilova o'zi qayta urinadi (har 10 soniyada va internet qaytishi bilan); texnik tafsilot "Batafsil" ostida.
 */
@Composable
fun ConnectingScreen(error: String?, server: String, onRetry: () -> Unit, onLogout: () -> Unit) {
    val context = LocalContext.current
    val colors = LocalAppColors.current
    var details by remember { mutableStateOf(false) }
    var seconds by remember { mutableStateOf(RETRY_SECONDS) }
    val online = remember(error) { hasInternet(context) }

    if (error != null) {
        LaunchedEffect(error) {
            seconds = RETRY_SECONDS
            while (seconds > 0) {
                delay(1000)
                seconds--
            }
            onRetry()
        }
        // Internet qaytdi — kutmasdan urinish
        val retry by androidx.compose.runtime.rememberUpdatedState(onRetry)
        androidx.compose.runtime.DisposableEffect(Unit) {
            val manager = context.getSystemService(android.net.ConnectivityManager::class.java)
            val main = android.os.Handler(android.os.Looper.getMainLooper())
            val callback = object : android.net.ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: android.net.Network) {
                    if (!online) main.post { retry() }
                }
            }
            runCatching { manager.registerDefaultNetworkCallback(callback) }
            onDispose { runCatching { manager.unregisterNetworkCallback(callback) } }
        }
    }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (error == null) {
            AppLogo(76)
            androidx.compose.material3.CircularProgressIndicator(Modifier.size(28.dp), color = MaterialTheme.colorScheme.onSurface, strokeWidth = 3.dp)
            Text(stringResource(R.string.connecting), style = MaterialTheme.typography.titleMedium)
            return@Column
        }
        Box(
            Modifier.size(64.dp).clip(androidx.compose.foundation.shape.CircleShape).background(colors.tile),
            contentAlignment = Alignment.Center,
        ) {
            Icon(if (online) Icons.Default.CloudOff else Icons.Default.WifiOff, contentDescription = null, modifier = Modifier.size(30.dp))
        }
        Text(stringResource(if (online) R.string.server_down else R.string.no_internet), style = MaterialTheme.typography.titleLarge, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        Text(
            stringResource(if (online) R.string.server_down_text else R.string.no_internet_text),
            color = colors.muted,
            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            modifier = Modifier.widthIn(max = 300.dp),
        )
        Text(stringResource(R.string.retry_in, seconds), style = MaterialTheme.typography.bodySmall, color = colors.muted)
        uz.dtaxi.core.ui.BigButton(stringResource(R.string.retry_now), onClick = onRetry, modifier = Modifier.widthIn(max = 360.dp))
        androidx.compose.material3.TextButton(onClick = { details = !details }) { Text(stringResource(R.string.details), color = colors.muted) }
        if (details) {
            Text(
                "$server\n$error",
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(colors.tile).padding(12.dp),
                style = MaterialTheme.typography.bodySmall,
                color = colors.muted,
            )
        }
        // Sinov ilovasida chiqib, login ekranida server manzilini o'zgartirish mumkin
        androidx.compose.material3.TextButton(onClick = onLogout) { Text(stringResource(R.string.logout), color = colors.muted) }
    }
}

private const val RETRY_SECONDS = 10

private fun hasInternet(context: android.content.Context): Boolean {
    val manager = context.getSystemService(android.net.ConnectivityManager::class.java) ?: return true
    val caps = manager.getNetworkCapabilities(manager.activeNetwork) ?: return false
    return caps.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_INTERNET)
}

/** Server vaqti (ISO, UTC) → telefonning mahalliy soati "14:05" */
internal fun localTime(iso: String?, pattern: String = "HH:mm"): String? = iso?.let {
    runCatching {
        java.time.OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.systemDefault())
            .format(java.time.format.DateTimeFormatter.ofPattern(pattern))
    }.getOrNull()
}

/** Dtaxi belgisi — sariq plitkada (kirish va ulanish ekranlari) */
@Composable
fun AppLogo(size: Int = 88) {
    androidx.compose.foundation.Image(
        painter = androidx.compose.ui.res.painterResource(R.drawable.ic_launcher),
        contentDescription = stringResource(R.string.app_name),
        modifier = Modifier.size(size.dp).clip(androidx.compose.foundation.shape.RoundedCornerShape((size / 4).dp)),
    )
}

private fun isBatteryExempt(context: android.content.Context): Boolean =
    (context.getSystemService(android.content.Context.POWER_SERVICE) as android.os.PowerManager).isIgnoringBatteryOptimizations(context.packageName)

/**
 * Batareya cheklovidan chiqarish: ba'zi telefonlarda (Samsung, Xiaomi) to'g'ridan-to'g'ri so'rov oynasi yo'q —
 * unda batareya sozlamalari ro'yxati, u ham bo'lmasa ilova sozlamalari ochiladi.
 */
private fun openBatterySettings(context: android.content.Context) {
    val pkg = Uri.parse("package:${context.packageName}")
    val attempts = buildList {
        if (!isBatteryExempt(context)) add(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, pkg))
        add(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        add(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, pkg))
    }
    for (intent in attempts) {
        val opened = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
        if (opened) return
    }
}
