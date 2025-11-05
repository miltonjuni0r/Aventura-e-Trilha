document.addEventListener("DOMContentLoaded", () => {
  const botoesReserva = document.querySelectorAll(".botao-pacote");
  const painel = document.getElementById("painelReserva");
  const fechar = document.querySelector(".fechar");
  const nomePacote = document.getElementById("nomePacote");
  const precoPacote = document.getElementById("precoPacote");
  const confirmar = document.getElementById("confirmarReserva");

  // Abrir o painel
  botoesReserva.forEach(botao => {
    botao.addEventListener("click", () => {
      const pacote = botao.parentElement;
      const titulo = pacote.querySelector("h3").innerText;
      const preco = pacote.querySelector(".preco").innerText;

      nomePacote.innerText = `Pacote: ${titulo}`;
      precoPacote.innerText = `Preço: ${preco}`;
      painel.style.display = "flex";

      // Limpa campos e mensagens anteriores
      document.getElementById("nomeCliente").value = "";
      document.getElementById("telefone").value = "";
      document.getElementById("email").value = "";
      document.getElementById("cpf").value = "";

      const mensagem = document.getElementById("mensagemConfirmacao");
      if (mensagem) mensagem.innerText = "";
    });
  });

  fechar.addEventListener("click", () => painel.style.display = "none");

  // Funções de validação
  function validarEmail(email) {
    return /\S+@\S+\.\S+/.test(email);
  }
  function validarCPF(cpf) {
    return /^\d{11}$/.test(cpf);
  }

  confirmar.addEventListener("click", () => {
    const nome = document.getElementById("nomeCliente").value.trim();
    const tel = document.getElementById("telefone").value.trim();
    const email = document.getElementById("email").value.trim();
    const cpf = document.getElementById("cpf").value.trim();

    let mensagem = document.getElementById("mensagemConfirmacao");
    if (!mensagem) {
      mensagem = document.createElement("p");
      mensagem.id = "mensagemConfirmacao";
      painel.querySelector(".painel-conteudo").appendChild(mensagem);
    }

    // 🔹 Reset visual antes de qualquer verificação
    mensagem.style.color = "";
    mensagem.style.fontWeight = "";
    mensagem.innerText = "";

    // 🔹 Validações
    if (!nome || !tel || !email || !cpf) {
      mensagem.textContent = "⚠️ Preencha todos os campos!";
      mensagem.style.color = "red";
      mensagem.style.fontWeight = "bold";
      return;
    }

    if (!validarEmail(email)) {
      mensagem.textContent = "⚠️ E-mail inválido! Certifique-se de incluir '@' e domínio.";
      mensagem.style.color = "red";
      mensagem.style.fontWeight = "bold";
      return;
    }

    if (!validarCPF(cpf)) {
      mensagem.textContent = "⚠️ CPF inválido! O CPF deve ter exatamente 11 dígitos numéricos.";
      mensagem.style.color = "red";
      mensagem.style.fontWeight = "bold";
      return;
    }

    // 🔹 Sucesso
    mensagem.textContent = `✅ Pedido confirmado! Enviaremos mais informações no seu contato, ${nome}.`;
    mensagem.style.color = "green";
    mensagem.style.fontWeight = "bold";
  });
});
