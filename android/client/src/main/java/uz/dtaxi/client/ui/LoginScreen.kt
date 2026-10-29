package uz.dtaxi.client.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.isImeVisible
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Call
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.Phones
import uz.dtaxi.core.ui.BigButton
import uz.dtaxi.core.ui.LanguageChip
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.PhoneField
import uz.dtaxi.core.ui.StepBar
import uz.dtaxi.core.ui.TileTextField

/** Kod qayta so'ralguncha kutish (server raqamga soatiga 3 ta qo'ng'iroq beradi) */
private const val RESEND_SECONDS = 60
private const val CODE_LENGTH = 4
private const val STEPS = 3

/**
 * Kirish (qaror #71): 1) telefon (davlat tanlanadi) → 2) qo'ng'iroqda aytilgan 4 xonali kod → 3) ism (NameScreen).
 * SMS yo'q (qaror #4).
 */
@Composable
fun LoginScreen(onLocaleChange: (String) -> Unit) {
    val repository = ClientApp.instance.repository
    val session = ClientApp.instance.session
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var country by remember { mutableStateOf(Phones.defaultCountry(context, BuildConfig.PHONE_COUNTRY)) }
    var phone by remember { mutableStateOf("") }
    var codeSent by remember { mutableStateOf(false) }
    var code by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var wait by remember { mutableIntStateOf(0) }
    val network = stringResource(R.string.network_error)
    val generic = stringResource(R.string.error)
    val tooMany = stringResource(R.string.too_many_requests)
    val e164 = Phones.e164(country, phone)

    fun message(result: Result.Error) = when (result.code) {
        -1 -> network
        429 -> tooMany
        else -> result.message ?: generic
    }

    fun sendCode() {
        if (loading || !Phones.isValid(country, phone)) return
        loading = true
        error = null
        scope.launch {
            when (val result = repository.requestCode(e164)) {
                is Result.Ok -> {
                    codeSent = true
                    code = ""
                    wait = RESEND_SECONDS
                }
                is Result.Error -> error = message(result)
            }
            loading = false
        }
    }

    fun verify() {
        loading = true
        error = null
        scope.launch {
            // Muvaffaqiyatda profil holatga yoziladi — MainActivity o'zi keyingi ekranga o'tadi
            (repository.verify(e164, code) as? Result.Error)?.let {
                error = message(it)
                code = ""
            }
            loading = false
        }
    }

    LaunchedEffect(wait) {
        if (wait > 0) {
            delay(1_000)
            wait--
        }
    }
    // 4 raqam terilishi bilan tekshiriladi
    LaunchedEffect(code) {
        if (code.length == CODE_LENGTH && !loading) verify()
    }
    BackHandler(enabled = codeSent) {
        codeSent = false
        error = null
    }

    if (!codeSent) {
        LoginPage(
            step = 1,
            top = {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Image(
                        painter = painterResource(R.drawable.ic_launcher),
                        contentDescription = null,
                        modifier = Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)),
                    )
                    Text(stringResource(R.string.app_name), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                }
                Spacer(Modifier.weight(1f))
                LanguageChip(session.locale, onSelect = onLocaleChange, container = LocalAppColors.current.tile)
            },
            title = stringResource(R.string.phone_title),
            subtitle = stringResource(R.string.phone_hint),
            bottom = {
                Text(
                    stringResource(R.string.privacy_consent),
                    style = MaterialTheme.typography.bodySmall,
                    color = LocalAppColors.current.muted,
                    textAlign = TextAlign.Center,
                    textDecoration = TextDecoration.Underline,
                    modifier = Modifier.fillMaxWidth().clickable { openPrivacy(context) }.padding(vertical = 12.dp),
                )
                BigButton(
                    stringResource(R.string.get_code),
                    onClick = ::sendCode,
                    enabled = Phones.isValid(country, phone),
                    loading = loading,
                )
            },
        ) {
            PhoneField(
                country,
                phone,
                onChange = { iso, digits ->
                    country = iso
                    phone = digits
                    error = null
                },
                imeAction = ImeAction.Go,
                onDone = ::sendCode,
                autoFocus = true,
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        }
    } else {
        LoginPage(
            step = 2,
            top = {
                IconButton(onClick = {
                    codeSent = false
                    error = null
                }, modifier = Modifier.offset(x = (-12).dp)) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.change_phone))
                }
            },
            title = stringResource(R.string.enter_code),
            subtitle = stringResource(R.string.calling_number, Phones.format(e164)),
            bottom = {
                BigButton(
                    stringResource(R.string.continue_),
                    onClick = ::verify,
                    enabled = code.length == CODE_LENGTH,
                    loading = loading,
                )
            },
        ) {
            TextButton(
                onClick = {
                    codeSent = false
                    error = null
                },
                modifier = Modifier.offset(x = (-12).dp),
            ) {
                Text(
                    stringResource(R.string.change_phone),
                    fontWeight = FontWeight.SemiBold,
                    textDecoration = TextDecoration.Underline,
                    color = MaterialTheme.colorScheme.onSurface,
                )
            }
            CallingCard()
            CodeCells(code, onChange = {
                code = it
                error = null
            })
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            if (wait > 0) {
                Text(
                    stringResource(R.string.resend_after, "%d:%02d".format(wait / 60, wait % 60)),
                    color = LocalAppColors.current.muted,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            } else {
                TextButton(onClick = ::sendCode, enabled = !loading, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.resend_code), fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onSurface)
                }
            }
        }
    }
}

/** Birinchi kirishda ism (haydovchi mijozni shunday chaqiradi); "Keyinroq" — o'tkazib yuborish */
@Composable
fun NameScreen(onSkip: () -> Unit) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val generic = stringResource(R.string.error)

    fun save() {
        if (loading || name.isBlank()) return
        loading = true
        scope.launch {
            (repository.updateProfile(name = name.trim()) as? Result.Error)?.let { error = it.message ?: generic }
            loading = false
        }
    }

    LoginPage(
        step = 3,
        top = { Spacer(Modifier.height(48.dp)) },
        title = stringResource(R.string.name_title),
        subtitle = stringResource(R.string.name_hint),
        bottom = {
            BigButton(stringResource(R.string.done), onClick = ::save, enabled = name.isNotBlank(), loading = loading)
            TextButton(onClick = onSkip, enabled = !loading, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                Text(stringResource(R.string.later), fontWeight = FontWeight.SemiBold, color = LocalAppColors.current.muted)
            }
        },
    ) {
        Text(stringResource(R.string.your_name), style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold)
        TileTextField(
            value = name,
            // Ismda raqam va belgilar bo'lmaydi (panel bilan bir xil)
            onValueChange = { value -> name = value.filter { it.isLetter() || it == ' ' || it in "'ʻʼ‘’`-" }.take(100) },
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { save() }),
        )
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
    }
}

/** Uch qadam uchun bir xil sahifa: tepa qator, qadam chizig'i, sarlavha, maydonlar, pastda tugmalar */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LoginPage(
    step: Int,
    top: @Composable RowScope.() -> Unit,
    title: String,
    subtitle: String,
    bottom: @Composable ColumnScope.() -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    val scroll = rememberScrollState()
    val keyboard = WindowInsets.isImeVisible
    // Klaviatura ochilganda maydon (sahifa oxirida) kesilib qolmasin
    LaunchedEffect(keyboard, scroll.maxValue) {
        if (keyboard) scroll.animateScrollTo(scroll.maxValue)
    }
    Column(
        Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.surface)
            .systemBarsPadding()
            .imePadding()
            .padding(horizontal = 20.dp),
    ) {
        Row(Modifier.fillMaxWidth().padding(top = 12.dp), verticalAlignment = Alignment.CenterVertically) { top() }
        Column(
            Modifier.weight(1f).verticalScroll(scroll),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            StepBar(step, STEPS, stringResource(R.string.step_of, step, STEPS), Modifier.padding(top = 20.dp, bottom = 12.dp))
            Text(title, fontSize = 28.sp, lineHeight = 32.sp, fontWeight = FontWeight.ExtraBold)
            Text(subtitle, color = LocalAppColors.current.muted, lineHeight = 21.sp, modifier = Modifier.padding(bottom = 12.dp))
            content()
        }
        Column(Modifier.fillMaxWidth().padding(bottom = 16.dp, top = 8.dp)) { bottom() }
    }
}

@Composable
private fun CallingCard() {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.18f))
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(
            Modifier.size(40.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primary),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Default.Call, contentDescription = null, tint = MaterialTheme.colorScheme.onPrimary, modifier = Modifier.size(20.dp))
        }
        Column {
            Text(stringResource(R.string.calling), fontWeight = FontWeight.SemiBold)
            Text(stringResource(R.string.calling_hint), style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
        }
    }
}

/** 4 katak: bitta yashirin maydon, raqamlar kataklarda; joriy katak qora chegarada */
@Composable
private fun CodeCells(code: String, onChange: (String) -> Unit) {
    val focus = remember { FocusRequester() }
    val onSurface = MaterialTheme.colorScheme.onSurface
    val tile = LocalAppColors.current.tile
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    BasicTextField(
        value = code,
        onValueChange = { value -> onChange(value.filter(Char::isDigit).take(CODE_LENGTH)) },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        modifier = Modifier.fillMaxWidth().padding(top = 8.dp).focusRequester(focus),
        decorationBox = {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                repeat(CODE_LENGTH) { i ->
                    val current = i == code.length
                    val description = stringResource(R.string.code_digit, i + 1)
                    Box(
                        Modifier
                            .weight(1f)
                            .height(68.dp)
                            .clip(RoundedCornerShape(16.dp))
                            .background(if (current) MaterialTheme.colorScheme.surface else tile)
                            .border(2.dp, if (current) onSurface else Color.Transparent, RoundedCornerShape(16.dp))
                            .semantics { contentDescription = description },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(code.getOrNull(i)?.toString().orEmpty(), fontSize = 28.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        },
    )
}
