package uz.dtaxi.core.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import uz.dtaxi.core.R
import uz.dtaxi.core.data.AppLocale

/** Kirish ekranidagi til tanlash: kichik tugma, bosilsa to'rt til ro'yxati */
@Composable
fun LanguageChip(selected: String, onSelect: (String) -> Unit, container: Color, modifier: Modifier = Modifier) {
    var open by remember { mutableStateOf(false) }
    val label = AppLocale.labels.getValue(AppLocale.normalize(selected))
    val description = stringResource(R.string.language_label, label)
    Box(modifier) {
        Row(
            Modifier
                .height(40.dp)
                .clip(RoundedCornerShape(20.dp))
                .background(container)
                .clickable { open = true }
                .semantics { contentDescription = description }
                .padding(start = 14.dp, end = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Text(label, fontWeight = FontWeight.SemiBold)
            Icon(Icons.Default.KeyboardArrowDown, contentDescription = null, modifier = Modifier.size(18.dp))
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            AppLocale.codes.forEach { code ->
                DropdownMenuItem(
                    text = { Text(AppLocale.labels.getValue(code)) },
                    trailingIcon = { if (code == selected) Icon(Icons.Default.Check, contentDescription = null) },
                    onClick = {
                        open = false
                        if (code != selected) onSelect(code)
                    },
                )
            }
        }
    }
}
