package uz.dtaxi.core.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import uz.dtaxi.core.data.AppLocale

/**
 * Sozlamalar guruhi: ustida kichik sarlavha, ichida kulrang plitkadagi qatorlar.
 * Qatorlar orasidagi chiziq — fon (1 dp oraliqdan ko'rinadi), har qator o'zi chiziq chizmaydi.
 */
@Composable
fun SettingsGroup(label: String?, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        label?.let {
            Text(
                it.uppercase(),
                Modifier.padding(start = 4.dp),
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Bold,
                letterSpacing = 0.6.sp,
                color = LocalAppColors.current.muted,
            )
        }
        Column(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(18.dp))
                .background(MaterialTheme.colorScheme.outlineVariant),
            verticalArrangement = Arrangement.spacedBy(1.dp),
            content = content,
        )
    }
}

/** Bitta sozlama: chapda belgi, nom (ostida izoh), o'ngda qiymat yoki boshqaruv; bosiladigan bo'lsa — strelka */
@Composable
fun SettingsItem(
    icon: ImageVector,
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    value: String? = null,
    titleColor: Color = Color.Unspecified,
    onClick: (() -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
) {
    val colors = LocalAppColors.current
    Row(
        modifier
            .fillMaxWidth()
            .heightIn(min = 60.dp)
            .background(colors.tile)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(
            Modifier.size(36.dp).clip(RoundedCornerShape(10.dp)).background(MaterialTheme.colorScheme.surface),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp), tint = if (titleColor == Color.Unspecified) MaterialTheme.colorScheme.onSurface else titleColor)
        }
        Column(Modifier.weight(1f)) {
            Text(title, fontWeight = FontWeight.Medium, fontSize = 16.sp, color = titleColor)
            subtitle?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = colors.muted, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        }
        value?.let { Text(it, color = colors.muted, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(0.6f, fill = false)) }
        trailing?.invoke()
        if (onClick != null && trailing == null) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = colors.muted)
        }
    }
}

/** Holat belgisi: yashil (ok), sariq (sozlash kerak) yoki kulrang (null — neytral, masalan bekor qilingan) */
@Composable
fun StatusPill(text: String, ok: Boolean?) {
    val colors = LocalAppColors.current
    val (container, content) = if (ok == null) {
        MaterialTheme.colorScheme.outlineVariant to colors.muted
    } else if (ok) {
        MaterialTheme.colorScheme.tertiaryContainer to MaterialTheme.colorScheme.onTertiaryContainer
    } else if (colors.dark) {
        Color(0xFF3A2A10) to Color(0xFFFFC56B)
    } else {
        Color(0xFFFFF1D6) to Color(0xFF8A5300)
    }
    Text(
        text,
        Modifier.clip(RoundedCornerShape(50)).background(container).padding(horizontal = 10.dp, vertical = 4.dp),
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
        color = content,
        maxLines = 1,
    )
}

/** Bir nechta variantdan biri (mavzu, tablar): kulrang yo'lak, tanlangani oq "tugma" */
@Composable
fun SegmentedChoice(options: List<Pair<String, String>>, selected: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    val colors = LocalAppColors.current
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(colors.tile).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        options.forEach { (key, label) ->
            val on = key == selected
            Box(
                Modifier
                    .weight(1f)
                    .height(40.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(if (on) MaterialTheme.colorScheme.surface else Color.Transparent)
                    .selectable(selected = on, role = Role.Tab, onClick = { if (!on) onSelect(key) }),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    label,
                    fontWeight = if (on) FontWeight.Bold else FontWeight.Medium,
                    color = if (on) MaterialTheme.colorScheme.onSurface else colors.muted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

/** Til qatori: joriy til o'ngda, bosilsa pastdan to'rt til ro'yxati */
@Composable
fun LanguageSetting(icon: ImageVector, title: String, selected: String, onSelect: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val current = AppLocale.normalize(selected)
    SettingsItem(icon, title, value = AppLocale.labels.getValue(current), onClick = { open = true })
    if (open) {
        ChoiceSheet(
            title = title,
            options = AppLocale.codes.map { it to AppLocale.labels.getValue(it) },
            selected = current,
            onDismiss = { open = false },
            onSelect = { code ->
                open = false
                if (code != current) onSelect(code)
            },
        )
    }
}

/** Pastdan chiqadigan tanlov ro'yxati (til va shu kabilar) */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChoiceSheet(title: String, options: List<Pair<String, String>>, selected: String?, onDismiss: () -> Unit, onSelect: (String) -> Unit) {
    val colors = LocalAppColors.current
    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = MaterialTheme.colorScheme.surface,
    ) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 4.dp))
            options.forEach { (key, label) ->
                val on = key == selected
                Row(
                    Modifier
                        .fillMaxWidth()
                        .height(54.dp)
                        .clip(RoundedCornerShape(14.dp))
                        .background(colors.tile)
                        .selectable(selected = on, role = Role.RadioButton, onClick = { onSelect(key) })
                        .padding(horizontal = 16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(label, Modifier.weight(1f), fontSize = 16.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Normal)
                    if (on) Icon(Icons.Default.Check, contentDescription = null)
                }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}
