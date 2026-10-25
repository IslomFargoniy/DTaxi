package uz.dtaxi.driver.ui

import uz.dtaxi.core.ui.LocalAppColors

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.ChatBubbleOutline
import androidx.compose.material.icons.filled.Headset
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Photo
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.Surface
import androidx.compose.material3.SuggestionChip
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
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
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.data.ChatMessage
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.Result
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** Ro'yxat qatori: sana ajratgichi yoki xabar (guruhdagi o'rni bilan) */
private sealed interface ChatRow {
    val key: String

    data class Day(val date: LocalDate) : ChatRow {
        override val key = "day-$date"
    }

    data class Bubble(val message: ChatMessage, val first: Boolean) : ChatRow {
        override val key = "m-${message.id}"
    }
}

private fun dateOf(iso: String?): LocalDate? = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).toLocalDate() }.getOrNull()
}

/** Kim yozgani: haydovchining o'zi yoki aniq operator — ketma-ket bir yozuvchi guruhlanadi */
private fun senderKey(m: ChatMessage) = if (m.mine) "me" else (m.senderName ?: "dispatcher")

private fun rows(messages: List<ChatMessage>): List<ChatRow> {
    val out = mutableListOf<ChatRow>()
    var lastDay: LocalDate? = null
    var lastSender: String? = null
    messages.forEach { m ->
        val day = dateOf(m.createdAt)
        if (day != null && day != lastDay) {
            out += ChatRow.Day(day)
            lastDay = day
            lastSender = null
        }
        out += ChatRow.Bubble(m, first = senderKey(m) != lastSender)
        lastSender = senderKey(m)
    }
    return out
}

/**
 * Dispetcher bilan chat (qaror #47), Telegram kabi: sana ajratgichlari, guruhlangan pufakchalar,
 * tezkor javoblar; yangi xabarlar Reverb orqali (DriverState.unread).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatScreen(onBack: () -> Unit, onCall: (() -> Unit)? = null) {
    val repository = DtaxiApp.instance.repository
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val dark = LocalAppColors.current.dark
    val messages = remember { mutableStateListOf<ChatMessage>() }
    val unread by DriverState.unread.collectAsState()
    val readUpTo by DriverState.chatReadUpTo.collectAsState()
    val order by DriverState.order.collectAsState()
    var text by remember { mutableStateOf("") }
    var loaded by remember { mutableStateOf(false) }
    // Yuqoriga aylantirilganda kelgan yangi xabarlar — "↓" tugmasida son
    var unseen by remember { mutableIntStateOf(0) }
    val listState = rememberLazyListState()
    val quick = listOf(
        stringResource(R.string.quick_client_late),
        stringResource(R.string.quick_address),
        stringResource(R.string.quick_on_way),
        stringResource(R.string.quick_traffic),
    )
    val list = rows(messages)
    val nearBottom by remember {
        derivedStateOf {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: 0
            info.totalItemsCount == 0 || last >= info.totalItemsCount - 2
        }
    }

    suspend fun load() {
        (repository.chat() as? Result.Ok)?.value?.let { fresh ->
            val before = messages.size
            val wasNearBottom = nearBottom
            messages.clear()
            messages.addAll(fresh.reversed())
            DriverState.setUnread(0)
            val added = messages.size - before
            when {
                !loaded || wasNearBottom -> {
                    if (messages.isNotEmpty()) listState.scrollToItem(rows(messages).lastIndex)
                    unseen = 0
                }
                added > 0 -> unseen += added
            }
            loaded = true
        }
    }
    LaunchedEffect(unread) { load() }
    // Dispetcher o'qidi — qayta yuklamasdan joyida ✓✓
    LaunchedEffect(readUpTo) {
        messages.indices.forEach { i ->
            val m = messages[i]
            if (m.mine && m.readAt == null && m.id <= readUpTo) messages[i] = m.copy(readAt = "read")
        }
    }
    LaunchedEffect(nearBottom) { if (nearBottom) unseen = 0 }

    fun send(body: String) {
        if (body.isBlank()) return
        text = ""
        scope.launch {
            (repository.sendChat(body.trim(), order?.id) as? Result.Ok)?.value?.let {
                messages.add(it)
                listState.animateScrollToItem(rows(messages).lastIndex)
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) } },
                title = {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        // Firma rangi (firm.properties) — boshqa firmada sariq emas
                        Box(Modifier.size(40.dp).background(MaterialTheme.colorScheme.primary, CircleShape), contentAlignment = Alignment.Center) {
                            Icon(Icons.Default.Headset, null, tint = MaterialTheme.colorScheme.onPrimary)
                        }
                        Column {
                            Text(stringResource(R.string.dispatcher), style = MaterialTheme.typography.titleMedium)
                            Text(
                                stringResource(R.string.chat_subtitle),
                                style = MaterialTheme.typography.bodySmall,
                                color = LocalAppColors.current.muted,
                            )
                        }
                    }
                },
                actions = {
                    if (onCall != null) {
                        IconButton(onClick = onCall) { Icon(Icons.Default.Call, stringResource(R.string.call_dispatcher)) }
                    }
                },
            )
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize().imePadding()) {
            Box(Modifier.weight(1f).fillMaxWidth().background(if (dark) Color(0xFF0F1115) else Color(0xFFEFEFEA))) {
                if (loaded && messages.isEmpty()) {
                    Column(Modifier.align(Alignment.Center), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Default.ChatBubbleOutline, null, Modifier.size(48.dp), tint = LocalAppColors.current.muted)
                        Spacer(Modifier.height(8.dp))
                        Text(stringResource(R.string.no_messages), color = LocalAppColors.current.muted)
                    }
                }
                LazyColumn(
                    Modifier.fillMaxSize(),
                    state = listState,
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 10.dp, vertical = 8.dp),
                ) {
                    items(list, key = { it.key }) { row ->
                        when (row) {
                            is ChatRow.Day -> DayChip(row.date, dark)
                            is ChatRow.Bubble -> MessageBubble(row.message, row.first, dark) { url ->
                                runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
                            }
                        }
                    }
                }
                if (!nearBottom) {
                    BadgedBox(
                        badge = { if (unseen > 0) Badge { Text("$unseen") } },
                        modifier = Modifier.align(Alignment.BottomEnd).padding(12.dp),
                    ) {
                        SmallFloatingActionButton(
                            onClick = { scope.launch { listState.animateScrollToItem(list.lastIndex.coerceAtLeast(0)) } },
                            containerColor = MaterialTheme.colorScheme.surface,
                        ) { Icon(Icons.Default.KeyboardArrowDown, stringResource(R.string.scroll_down)) }
                    }
                }
            }

            // Tezkor javoblar — bir qatorda, suriladi
            Row(
                Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 8.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                quick.forEach { reply -> SuggestionChip(onClick = { send(reply) }, label = { Text(reply) }, shape = RoundedCornerShape(50)) }
            }

            Row(
                Modifier.fillMaxWidth().padding(start = 8.dp, end = 8.dp, bottom = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                TextField(
                    value = text,
                    onValueChange = { text = it },
                    placeholder = { Text(stringResource(R.string.message)) },
                    modifier = Modifier.weight(1f),
                    shape = RoundedCornerShape(24.dp),
                    maxLines = 4,
                    keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
                    colors = TextFieldDefaults.colors(
                        focusedIndicatorColor = Color.Transparent,
                        unfocusedIndicatorColor = Color.Transparent,
                        disabledIndicatorColor = Color.Transparent,
                        focusedContainerColor = MaterialTheme.colorScheme.surfaceVariant,
                        unfocusedContainerColor = MaterialTheme.colorScheme.surfaceVariant,
                    ),
                )
                FilledIconButton(
                    onClick = { send(text) },
                    enabled = text.isNotBlank(),
                    modifier = Modifier.size(48.dp),
                    colors = IconButtonDefaults.filledIconButtonColors(
                        containerColor = MaterialTheme.colorScheme.primary,
                        contentColor = MaterialTheme.colorScheme.onPrimary,
                    ),
                ) { Icon(Icons.AutoMirrored.Filled.Send, stringResource(R.string.send)) }
            }
        }
    }
}

@Composable
private fun DayChip(date: LocalDate, dark: Boolean) {
    val today = LocalDate.now()
    val label = when (date) {
        today -> stringResource(R.string.today)
        today.minusDays(1) -> stringResource(R.string.yesterday)
        else -> date.format(DateTimeFormatter.ofPattern("dd.MM.yyyy"))
    }
    Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
        Surface(shape = RoundedCornerShape(50), color = if (dark) Color(0xFF262A31) else Color(0x33000000)) {
            Text(
                label,
                Modifier.padding(horizontal = 12.dp, vertical = 4.dp),
                style = MaterialTheme.typography.labelMedium,
                color = if (dark) Color(0xFFA6ADBA) else Color.White,
            )
        }
    }
}

@Composable
private fun MessageBubble(m: ChatMessage, first: Boolean, dark: Boolean, onOpen: (String) -> Unit) {
    val mine = m.mine
    // Yuboruvchi tomondagi kichik "dum" burchak (Telegram kabi) — faqat guruhdagi birinchisida
    val tail = if (first) 4.dp else 18.dp
    val shape = if (mine) RoundedCornerShape(18.dp, tail, 18.dp, 18.dp) else RoundedCornerShape(tail, 18.dp, 18.dp, 18.dp)
    val container = if (mine) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface
    val content = if (mine) Color(0xFF111111) else MaterialTheme.colorScheme.onSurface
    val meta = if (mine) Color(0x99111111) else LocalAppColors.current.muted

    Row(
        Modifier.fillMaxWidth().padding(top = if (first) 6.dp else 2.dp),
        horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start,
    ) {
        Surface(shape = shape, color = container, shadowElevation = 1.dp, modifier = Modifier.widthIn(max = 300.dp)) {
            Column(Modifier.padding(horizontal = 12.dp, vertical = 7.dp)) {
                // Bir nechta operator yozishi mumkin — kim yozgani (guruhning birinchisida)
                if (!mine && first) {
                    Text(
                        m.senderName ?: stringResource(R.string.dispatcher),
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                        color = if (dark) Color(0xFF60A5FA) else Color(0xFF2563EB),
                    )
                }
                m.attachmentUrl?.let { url ->
                    Row(
                        Modifier.padding(vertical = 4.dp)
                            .background(if (mine) Color(0x22111111) else MaterialTheme.colorScheme.surfaceVariant, RoundedCornerShape(12.dp))
                            .clickable { onOpen(url) }
                            .padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        Icon(Icons.Default.Photo, null, tint = content)
                        Text(stringResource(R.string.photo), color = content, fontWeight = FontWeight.Medium)
                    }
                }
                m.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = content) }
                Text(
                    // Telegram kabi: ✓ yuborildi, ✓✓ dispetcher o'qidi
                    (localTime(m.createdAt) ?: "") + if (!mine) "" else if (m.readAt != null) "  ✓✓" else "  ✓",
                    Modifier.align(Alignment.End),
                    style = MaterialTheme.typography.labelSmall,
                    color = meta,
                )
            }
        }
    }
}
